<?php

namespace App\Services;

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
        $query = ItunesTradeAccount::query()->with(['user','country','plan']);

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
        $pageNum = $params['pageNum'] ?? 1;
        $pageSize = min($params['pageSize'] ?? 20, 10000);

        // 执行分页查询
        $result = $query->orderBy('updated_at', 'desc')
                       ->paginate($pageSize, ['*'], 'page', $pageNum);

        $accounts = collect($result->items());
        // 转换为API格式
        $data = $accounts->map(function ($account) {
            return $account->toApiArray();
        })->toArray();

        return [
            'data' => $data,
            'total' => $result->total(),
            'pageNum' => $result->currentPage(),
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
        $successCount = 0;
        $restoredAccounts = [];
        $createdAccounts = [];
        $updatedAccounts = [];
        $loginItems = [];

        DB::beginTransaction();
        try {
            foreach ($accountsData as $accountData) {
                $account = $accountData['account'];
                $password = $accountData['password'];
                $apiUrl = $accountData['apiUrl'] ?? null;
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
                            'password' => $password,
                            'api_url' => $apiUrl,
                            'status' => ItunesTradeAccount::STATUS_PROCESSING,
                            'uid' => Auth::id(),
                            'login_status' => 'invalid', // 重置登录状态
                            'plan_id' => null, // 重置计划绑定
                            'current_plan_day' => 1,
                        ]);

                        $restoredAccounts[] = $existing;
                        $loginItems[] = [
                            'id' => $existing->id,
                            'username' => $account,
                            'password' => $password,
                            'VerifyUrl' => $apiUrl
                        ];
                        $successCount++;
                    } else {
                        // 如果是有效账号，更新其信息
                        $existing->update([
                            'password' => $password,
                            'api_url' => $apiUrl,
                            'uid' => Auth::id(),
                            'login_status' => 'invalid', // 重置登录状态，需要重新验证
                        ]);

                        $updatedAccounts[] = $existing;
                        $loginItems[] = [
                            'id' => $existing->id,
                            'username' => $account,
                            'password' => $password,
                            'VerifyUrl' => $apiUrl
                        ];
                        $successCount++;
                    }
                } else {
                    // 创建新账号
                    $newAccount = ItunesTradeAccount::create([
                        'account' => $account,
                        'password' => $password,
                        'api_url' => $apiUrl,
                        'country_code' => $country,
                        'login_status' => 'invalid',
                        'status' => ItunesTradeAccount::STATUS_PROCESSING,
                        'uid' => Auth::id(),
                        'account_type' => $type
                    ]);

                    $createdAccounts[] = $newAccount;
                    $loginItems[] = [
                        'id' => $newAccount->id,
                        'username' => $account,
                        'password' => $password,
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
                'successCount' => $successCount,
                'restoredCount' => count($restoredAccounts),
                'createdCount' => count($createdAccounts),
                'updatedCount' => count($updatedAccounts),
                'accounts' => $allAccounts->map->toApiArray()->toArray(),
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
        $payload = ['list' => array_map(function ($item) {
            return [
                'id' => $item['id'],
                'username' => $item['username'],
                'password' => $item['password'],
                'VerifyUrl' => $item['VerifyUrl']
            ];
        }, $items)];

        $response = Http::post('http://47.76.200.188:8080/api/login_poll/new', $payload)->json();
        Log::channel('websocket_monitor')->info('发送登录请求', $response);
        if ($response['code'] !== 0) {
            throw new Exception("创建登录任务失败: ".$response['msg']);
        }

//        // 保存任务记录
//        $task = AccountLoginTask::create([
//            'task_id' => $response['data']['task_id'],
//            'creator_id' => Auth::id(),
//            'overall_status' => 'pending',
//            'started_at' => now(),
//        ]);
//
//        // 保存任务项
//        foreach ($items as $item) {
//            AccountLoginTaskItem::create([
//                'task_id' => $task->id,
//                'account_id' => $item['id'],
//                'status' => 'pending',
//            ]);
//        }

        return $response['data'];
    }

    /**
     * 更新账号状态
     */
    public function updateAccountStatus(int $id, string $status): ?ItunesTradeAccount
    {
        $account = ItunesTradeAccount::find($id);

        if (!$account) {
            return null;
        }

        $account->update(['status' => $status]);

        return $account;
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
        $payload = ['list' => array_map(function ($item) {
            return [
                'username' => $item['username'],
            ];
        }, $items)];

        $response = Http::post('http://47.76.200.188:8080/api/del_users', $payload)->json();

        if ($response['code'] !== 0) {
            throw new Exception("删除接口账户登录失败: ".$response['msg']);
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
            $loginAccount = [
                'username' => $account->account,
            ];
            $deleteLoginRespond = $giftCardApiClient->deleteUserLogins($loginAccount);
            Log::channel('websocket_monitor')->info('删除的账号：'. json_encode($deleteLoginRespond));
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
            'plan_id' => $planId,
            'current_plan_day' => 1, // 绑定新计划时重置为第1天
            'status' => ItunesTradeAccount::STATUS_WAITING,
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
            'plan_id' => null,
            'current_plan_day' => null,
            'status' => ItunesTradeAccount::STATUS_WAITING,
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
        $total = ItunesTradeAccount::count();
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
            'total' => $total,
            'by_status' => $byStatus,
            'by_country' => $byCountry,
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
