<?php

namespace App\Services;

use App\Models\ItunesTradeAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ItunesTradeAccountService
{
    /**
     * 获取账号列表（分页）
     */
    public function getAccountsWithPagination(array $params): array
    {
        $query = ItunesTradeAccount::query();

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
        $pageSize = min($params['pageSize'] ?? 20, 100);

        // 执行分页查询
        $result = $query->orderBy('created_at', 'desc')
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
    public function batchImportAccounts(string $country, array $accountsData): array
    {
        $successCount = 0;
        $failCount = 0;
        $duplicateAccounts = [];
        $createdAccounts = [];

        DB::beginTransaction();
        try {
            foreach ($accountsData as $accountData) {
                $account = $accountData['account'];
                $password = $accountData['password'];
                $apiUrl = $accountData['apiUrl'] ?? null;

                // 检查是否已存在
                $existing = ItunesTradeAccount::where('account', $account)
                                            ->where('country_code', $country)
                                            ->first();

                if ($existing) {
                    $duplicateAccounts[] = $account;
                    $failCount++;
                    continue;
                }

                // 创建新账号
                $newAccount = ItunesTradeAccount::create([
                    'account' => $account,
                    'password' => $password,
                    'api_url' => $apiUrl,
                    'country_code' => $country,
                    'status' => ItunesTradeAccount::STATUS_WAITING,
                    'uid' => Auth::id()
                ]);

                $createdAccounts[] = $newAccount;
                $successCount++;
            }

            DB::commit();

            return [
                'successCount' => $successCount,
                'failCount' => $failCount,
                'duplicateAccounts' => $duplicateAccounts,
                'accounts' => collect($createdAccounts)->map(function ($account) {
                    return $account->toApiArray();
                })->toArray(),
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
     * 删除账号
     */
    public function deleteAccount(int $id): bool
    {
        $account = ItunesTradeAccount::find($id);

        if (!$account) {
            return false;
        }

        return $account->delete();
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
            'current_plan_day' => 0,
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
