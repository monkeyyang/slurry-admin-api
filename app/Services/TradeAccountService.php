<?php

namespace App\Services;

use App\Models\TradeAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class TradeAccountService
{
    /**
     * 批量导入账号
     *
     * @param array $data
     * @return array
     */
    public function batchImportAccounts(array $data): array
    {
        $successCount = 0;
        $failCount = 0;
        $duplicateAccounts = [];
        $accounts = [];

        // 获取当前用户信息（这里需要根据实际认证系统调整）
        $currentUser = auth()->user();
        $importedBy = $currentUser ? $currentUser->name : 'System';
        $importedByUserId = $currentUser ? $currentUser->id : null;
        $importedByNickname = $currentUser ? ($currentUser->nickname ?? $currentUser->name) : 'System';

        DB::beginTransaction();
        
        try {
            foreach ($data['accounts'] as $accountData) {
                try {
                    // 检查账号是否已存在
                    $existingAccount = TradeAccount::where('account', $accountData['account'])->first();
                    
                    if ($existingAccount) {
                        $duplicateAccounts[] = $accountData['account'];
                        $failCount++;
                        continue;
                    }

                    // 创建新账号
                    $account = new TradeAccount();
                    $account->account = $accountData['account'];
                    $account->setEncryptedPassword($accountData['password'] ?? '');
                    $account->api_url = $accountData['apiUrl'] ?? null;
                    $account->country = $data['country'];
                    $account->status = 'active';
                    $account->imported_by = $importedBy;
                    $account->imported_by_user_id = $importedByUserId;
                    $account->imported_by_nickname = $importedByNickname;
                    $account->imported_at = now();
                    
                    $account->save();

                    $accounts[] = $account->toApiArray();
                    $successCount++;

                } catch (Exception $e) {
                    Log::channel('gift_card_exchange')->error('Failed to import account: ' . $accountData['account'], [
                        'error' => $e->getMessage(),
                        'account_data' => $accountData
                    ]);
                    $failCount++;
                }
            }

            DB::commit();

            Log::channel('gift_card_exchange')->info('Batch import accounts completed', [
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'duplicate_count' => count($duplicateAccounts),
                'country' => $data['country'],
                'imported_by' => $importedBy
            ]);

            return [
                'successCount' => $successCount,
                'failCount' => $failCount,
                'duplicateAccounts' => $duplicateAccounts,
                'accounts' => $accounts,
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::channel('gift_card_exchange')->error('Batch import accounts failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * 更新账号状态
     *
     * @param TradeAccount $account
     * @param string $status
     * @return TradeAccount
     */
    public function updateAccountStatus(TradeAccount $account, string $status): TradeAccount
    {
        try {
            $oldStatus = $account->status;
            $account->status = $status;
            $account->save();

            Log::channel('gift_card_exchange')->info('Account status updated', [
                'account_id' => $account->id,
                'account' => $account->account,
                'old_status' => $oldStatus,
                'new_status' => $status,
                'updated_by' => auth()->user()->name ?? 'System'
            ]);

            return $account;

        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error('Failed to update account status', [
                'account_id' => $account->id,
                'account' => $account->account,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 批量删除账号
     *
     * @param array $ids
     * @return array
     */
    public function batchDeleteAccounts(array $ids): array
    {
        try {
            DB::beginTransaction();

            $accounts = TradeAccount::whereIn('id', $ids)->get();
            $deletedCount = 0;
            $deletedAccounts = [];

            foreach ($accounts as $account) {
                $deletedAccounts[] = [
                    'id' => $account->id,
                    'account' => $account->account,
                    'country' => $account->country
                ];
                $account->delete();
                $deletedCount++;
            }

            DB::commit();

            Log::channel('gift_card_exchange')->info('Batch delete accounts completed', [
                'deleted_count' => $deletedCount,
                'deleted_accounts' => $deletedAccounts,
                'deleted_by' => auth()->user()->name ?? 'System'
            ]);

            return [
                'deletedCount' => $deletedCount,
                'deletedAccounts' => $deletedAccounts,
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::channel('gift_card_exchange')->error('Batch delete accounts failed', [
                'ids' => $ids,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 获取账号列表（带分页和筛选）
     *
     * @param array $params
     * @return array
     */
    public function getAccountsList(array $params): array
    {
        $query = TradeAccount::query()->with('countryInfo');

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

        if (!empty($params['importedBy'])) {
            $query->byImportedBy($params['importedBy']);
        }

        if (!empty($params['startTime']) || !empty($params['endTime'])) {
            $query->byTimeRange($params['startTime'] ?? null, $params['endTime'] ?? null);
        }

        // 分页参数
        $pageNum = $params['pageNum'] ?? 1;
        $pageSize = min($params['pageSize'] ?? 10, 100); // 最大100条

        // 执行分页查询
        $result = $query->orderBy('created_at', 'desc')
                       ->paginate($pageSize, ['*'], 'page', $pageNum);

        return [
            'data' => collect($result->items())->map(function ($account) {
                return $account->toApiArray();
            })->toArray(),
            'total' => $result->total(),
            'pageNum' => $result->currentPage(),
            'pageSize' => $result->perPage(),
        ];
    }

    /**
     * 检查账号是否已存在
     *
     * @param string $account
     * @return bool
     */
    public function accountExists(string $account): bool
    {
        return TradeAccount::where('account', $account)->exists();
    }

    /**
     * 解析账号和密码字符串
     * 支持格式：
     * - "account password"
     * - "account password apiUrl"
     * - "account\tpassword\tapiUrl"
     *
     * @param string $accountString
     * @return array
     */
    public function parseAccountString(string $accountString): array
    {
        $result = [
            'account' => '',
            'password' => '',
            'apiUrl' => null
        ];

        // 先尝试制表符分隔
        if (strpos($accountString, "\t") !== false) {
            $parts = explode("\t", $accountString);
        } else {
            // 再尝试空格分隔
            $parts = explode(" ", $accountString);
        }

        if (count($parts) >= 2) {
            $result['account'] = trim($parts[0]);
            $result['password'] = trim($parts[1]);

            // 检查是否有第三部分且是API链接
            if (count($parts) >= 3) {
                $potentialApi = trim($parts[2]);
                if (preg_match('/^https?:\/\//', $potentialApi)) {
                    $result['apiUrl'] = $potentialApi;
                }
            }

            return $result;
        }

        // 如果没有找到分隔符，返回原始字符串作为账号
        $result['account'] = trim($accountString);
        return $result;
    }
} 