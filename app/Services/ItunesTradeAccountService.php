<?php

namespace App\Services;

use App\Jobs\ProcessAppleAccountLogoutJob;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ItunesTradeAccountService
{
    /**
     * 获取账号列表（分页）
     */
    public function getAccountsWithPagination(array $params): array
    {
        $query = ItunesTradeAccount::query()->with(['user', 'country', 'plan']);

        // 应用筛选条件
        if (!empty($params['account'])) {
            $query->byAccount($params['account']);
        }

        if (!empty($params['country'])) {
            $query->byCountry($params['country']);
        }

        if (!empty($params['status'])) {
            $query->byStatus($params['status']);
        }

        if (!empty($params['type'])) {
            $query->byType($params['type']);
        }

        if (!empty($params['loginStatus'])) {
            $query->byLoginStatus($params['loginStatus']);
        }

        if (!empty($params['uid'])) {
            $query->byUser($params['uid']);
        }

        if (!empty($params['startTime']) && !empty($params['endTime'])) {
            $query->byTimeRange($params['startTime'], $params['endTime']);
        }

        // 分页参数
        $pageNum  = $params['pageNum'] ?? 1;
        $pageSize = min($params['pageSize'] ?? 20, 10000);

        // 执行分页查询
        $result = $query->orderBy('updated_at', 'desc')->orderBy('amount', 'desc')
                        ->paginate($pageSize, ['*'], 'page', $pageNum);

        $accounts = collect($result->items());
        // 转换为API格式
        $data = $accounts->map(function ($account) {
            return $account->toApiArray();
        })->toArray();

        return [
            'data'     => $data,
            'total'    => $result->total(),
            'pageNum'  => $result->currentPage(),
            'pageSize' => $result->perPage(),
        ];
    }

    /**
     * 获取单个账号详情
     */
    public function getAccountDetail(int $id): ?array
    {
        $account = ItunesTradeAccount::find($id);

        if (!$account) {
            return null;
        }

        return $account->toDetailApiArray();
    }

    /**
     * 批量导入账号
     */
    public function batchImportAccounts(string $country, array $accountsData, string $type): array
    {
        $successCount     = 0;
        $restoredAccounts = [];
        $createdAccounts  = [];
        $updatedAccounts  = [];
        $loginItems       = [];

        DB::beginTransaction();
        try {
            foreach ($accountsData as $accountData) {
                $account  = $accountData['account'];
                $password = $accountData['password'];
                $apiUrl   = $accountData['apiUrl'] ?? null;
//                $loginItems[] = [
//
//                            'username' => $account,
//                            'password' => $password,
//                            'VerifyUrl' => $apiUrl
//                        ];
//                var_dump($loginItems);exit;

                // 检查是否已存在（包括已删除的）
                $existing = ItunesTradeAccount::withTrashed()
                                              ->where('account', $account)
                                              ->where('country_code', $country)
                                              ->where('account_type', $type)
                                              ->first();

                if ($existing) {
                    if ($existing->trashed()) {
                        // 如果是已删除的账号，恢复并更新信息
                        $existing->restore();
                        $existing->update([
                                              'password'         => $password,
                                              'api_url'          => $apiUrl,
                                              'status'           => ItunesTradeAccount::STATUS_PROCESSING,
                                              'uid'              => Auth::id(),
                                              'login_status'     => 'invalid', // 重置登录状态
                                              'plan_id'          => null, // 重置计划绑定
                                              'current_plan_day' => 1,
                                          ]);

                        $restoredAccounts[] = $existing;
                        $loginItems[]       = [
                            'id'        => $existing->id,
                            'username'  => $account,
                            'password'  => $password,
                            'VerifyUrl' => $apiUrl
                        ];
                        $successCount++;
                    } else {
                        // 如果是有效账号，更新其信息
                        $existing->update([
                                              'password'     => $password,
                                              'api_url'      => $apiUrl,
                                              'uid'          => Auth::id(),
                                              'login_status' => 'invalid', // 重置登录状态，需要重新验证
                                          ]);

                        $updatedAccounts[] = $existing;
                        $loginItems[]      = [
                            'id'        => $existing->id,
                            'username'  => $account,
                            'password'  => $password,
                            'VerifyUrl' => $apiUrl
                        ];
                        $successCount++;
                    }
                } else {
                    // 创建新账号
                    $newAccount = ItunesTradeAccount::create([
                                                                 'account'      => $account,
                                                                 'password'     => $password,
                                                                 'api_url'      => $apiUrl,
                                                                 'country_code' => $country,
                                                                 'login_status' => 'invalid',
                                                                 'status'       => ItunesTradeAccount::STATUS_PROCESSING,
                                                                 'uid'          => Auth::id(),
                                                                 'account_type' => $type
                                                             ]);

                    $createdAccounts[] = $newAccount;
                    $loginItems[]      = [
                        'id'        => $newAccount->id,
                        'username'  => $account,
                        'password'  => $password,
                        'VerifyUrl' => $apiUrl
                    ];

                    $successCount++;
                }
            }

            DB::commit();

            // 创建登录任务
            $taskResponse = $this->createLoginTask($loginItems);
            Log::channel('websocket_monitor')->info('登录回调：', $taskResponse);
            $allAccounts = collect($createdAccounts)
                ->concat($restoredAccounts)
                ->concat($updatedAccounts);

            return [
                'successCount'  => $successCount,
                'restoredCount' => count($restoredAccounts),
                'createdCount'  => count($createdAccounts),
                'updatedCount'  => count($updatedAccounts),
                'accounts'      => $allAccounts->map->toApiArray()->toArray(),
            ];

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * @throws Exception
     */
    protected function createLoginTask(array $items)
    {
        $giftCardApiClient = new GiftCardApiClient();

        $response = $giftCardApiClient->createLoginTask($items);
        Log::channel('websocket_monitor')->info('发送登录请求', $response);

        if ($response['code'] !== 0) {
            throw new Exception("创建登录任务失败: " . $response['msg']);
        }

        return $response['data'];
    }

    /**
     * 更新账号状态
     * @throws Exception
     */
    public function updateAccountStatus(int $id, string $status, string $reason = null): ?ItunesTradeAccount
    {
        $account = ItunesTradeAccount::find($id);

        if (!$account) {
            return null;
        }

        if ($account->status == ItunesTradeAccount::STATUS_BANNED) { // 状态已禁用，禁止改变状态
            throw new Exception('当前账号已禁用，无法修改状态');
        }

        $updateData = ['status' => $status];
        // 提出账号必须填写原因
        if ($status == ItunesTradeAccount::STATUS_PROPOSED) {
            if (empty($reason)) throw new Exception('请填写提出账号的原因');
            $updateData['remark'] = $reason;
        }

        $account->update($updateData);
        if ($status == ItunesTradeAccount::STATUS_BANNED) { // 当状态变为禁用时需要登出账号
            // 请求登出此账号
            ProcessAppleAccountLogoutJob::dispatch($account->id, 'account-banned');
        }

        return $account;
    }

    /**
     * 根据用户名禁用账号
     */
    public function banAccountByUsername(string $username, string $reason = '', int $code = null): ?ItunesTradeAccount
    {
        // 查找账号
        $account = ItunesTradeAccount::where('account', $username)->first();

        if (!$account) {
            throw new Exception('账号不存在');
        }

        // 检查账号是否已经被禁用
        if ($account->status === ItunesTradeAccount::STATUS_BANNED) {
            throw new Exception('账号已经被禁用');
        }

        // 更新账号状态为禁用
        $account->update(['status' => ItunesTradeAccount::STATUS_BANNED]);

        // 请求登出此账号
        ProcessAppleAccountLogoutJob::dispatch($account->id, 'account-banned');

        // 发送微信通知
        $this->sendWechatNotification($account, $reason);

        // 记录操作日志
        Log::info('账号被禁用', [
            'account_id' => $account->id,
            'account'    => $account->account,
            'reason'     => $reason,
            'code'       => $code,
            'operator'   => 'api_lock_status'
        ]);

        return $account;
    }

    /**
     * 发送微信通知
     */
    private function sendWechatNotification(ItunesTradeAccount $account, string $reason = ''): void
    {
        try {
            $roomId = config('wechat.default_rooms.default', '45958721463@chatroom');

            // 构建通知消息
            $message = " ⚠️  账号：{$account->account} 被禁用请注意查看";
            if (!empty($reason)) {
                $message .= "\n原因：{$reason}";
            }
            $message .= "\n时间：" . now()->format('Y-m-d H:i:s');

            // 发送微信消息
            $result = send_msg_to_wechat($roomId, $message, 'MT_SEND_TEXTMSG', true, 'account-ban');

            if ($result) {
                Log::info('账号禁用微信通知发送成功', [
                    'account_id' => $account->id,
                    'account'    => $account->account,
                    'room_id'    => $roomId,
                    'message_id' => $result
                ]);
            } else {
                Log::error('账号禁用微信通知发送失败', [
                    'account_id' => $account->id,
                    'account'    => $account->account,
                    'room_id'    => $roomId
                ]);
            }

        } catch (\Exception $e) {
            Log::error('发送微信通知异常', [
                'account_id' => $account->id,
                'account'    => $account->account,
                'error'      => $e->getMessage()
            ]);
        }
    }

    /**
     * 请求接口删除账号登录态
     *
     * @param array $items
     * @return mixed
     * @throws Exception
     */
    protected function deleteApiLoginUsers(array $items): mixed
    {
        $giftCardApiClient = new GiftCardApiClient();

        $response = $giftCardApiClient->deleteUserLogins($items);

        if ($response['code'] !== 0) {
            throw new Exception("删除接口账户登录失败: " . $response['msg']);
        }

        return $response['data'];
    }


    /**
     * 删除账号
     */
    public function deleteAccount(int $id): bool
    {
        $account = ItunesTradeAccount::find($id);

        if (!$account) {
            return false;
        }
        $giftCardApiClient = new GiftCardApiClient();
        try {
            $loginAccount       = [
                'username' => $account->account,
            ];
            $deleteLoginRespond = $giftCardApiClient->deleteUserLogins($loginAccount);
            Log::channel('websocket_monitor')->info('删除的账号：' . json_encode($deleteLoginRespond));
            return $account->delete();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 批量删除账号
     */
    public function batchDeleteAccounts(array $ids): int
    {
        return ItunesTradeAccount::whereIn('id', $ids)->delete();
    }

    /**
     * 绑定账号到计划
     */
    public function bindAccountToPlan(int $accountId, int $planId): ?ItunesTradeAccount
    {
        $account = ItunesTradeAccount::find($accountId);

        if (!$account) {
            return null;
        }

        $account->update([
                             'plan_id'          => $planId,
                             'current_plan_day' => 1, // 绑定新计划时重置为第1天
                             'status'           => ItunesTradeAccount::STATUS_WAITING,
                         ]);

        return $account;
    }

    /**
     * 解绑账号计划
     */
    public function unbindAccountFromPlan(int $accountId): ?ItunesTradeAccount
    {
        $account = ItunesTradeAccount::find($accountId);

        if (!$account) {
            return null;
        }

        $account->update([
                             'plan_id'          => null,
                             'current_plan_day' => null,
                             'status'           => ItunesTradeAccount::STATUS_WAITING,
                         ]);

        return $account;
    }

    /**
     * 更新账号登录状态
     */
    public function updateLoginStatus(int $id, string $loginStatus): ?ItunesTradeAccount
    {
        $account = ItunesTradeAccount::find($id);

        if (!$account) {
            return null;
        }

        $account->update(['login_status' => $loginStatus]);

        return $account;
    }

    /**
     * 获取统计信息
     */
    public function getStatistics(): array
    {
        $total    = ItunesTradeAccount::count();
        $byStatus = ItunesTradeAccount::selectRaw('status, count(*) as count')
                                      ->groupBy('status')
                                      ->pluck('count', 'status')
                                      ->toArray();

        $byCountry = ItunesTradeAccount::selectRaw('country, count(*) as count')
                                       ->groupBy('country')
                                       ->pluck('count', 'country')
                                       ->toArray();

        $byLoginStatus = ItunesTradeAccount::selectRaw('login_status, count(*) as count')
                                           ->whereNotNull('login_status')
                                           ->groupBy('login_status')
                                           ->pluck('count', 'login_status')
                                           ->toArray();

        return [
            'total'           => $total,
            'by_status'       => $byStatus,
            'by_country'      => $byCountry,
            'by_login_status' => $byLoginStatus,
        ];
    }

    /**
     * 验证账号密码
     */
    public function verifyAccountPassword(int $id, string $password): bool
    {
        $account = ItunesTradeAccount::find($id);

        if (!$account) {
            return false;
        }

        return Hash::check($password, $account->password);
    }

    /**
     * 更新账号密码
     */
    public function updateAccountPassword(int $id, string $newPassword): ?ItunesTradeAccount
    {
        $account = ItunesTradeAccount::find($id);

        if (!$account) {
            return null;
        }

        $account->update(['password' => $newPassword]);

        return $account;
    }

    /**
     * 获取可用账号（未绑定计划的账号）
     */
    public function getAvailableAccounts(string $country = null): Collection
    {
        $query = ItunesTradeAccount::whereNull('plan_id')
                                   ->where('status', ItunesTradeAccount::STATUS_WAITING);

        if ($country) {
            $query->where('country', $country);
        }

        return $query->get();
    }

    /**
     * 按计划获取账号
     */
    public function getAccountsByPlan(int $planId): Collection
    {
        return ItunesTradeAccount::where('plan_id', $planId)->get();
    }

    /**
     * 按群聊获取账号
     */
    public function getAccountsByRoom(int $roomId): Collection
    {
        return ItunesTradeAccount::where('room_id', $roomId)->get();
    }
}
