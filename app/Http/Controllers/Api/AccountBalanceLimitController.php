<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountBalanceLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountBalanceLimitController extends Controller
{
    /**
     * 获取账号额度上限列表
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getBalanceLimits(Request $request): JsonResponse
    {
        try {
            $query = AccountBalanceLimit::query();
            
            // 应用过滤
            if ($request->has('account')) {
                $query->where('account', 'like', '%' . $request->input('account') . '%');
            }
            
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }
            
            // 应用排序
            $sortField = $request->input('sortField', 'created_at');
            $sortOrder = $request->input('sortOrder', 'desc');
            
            // 有效排序字段
            $validSortFields = ['account', 'balance_limit', 'current_balance', 'status', 'last_redemption_at', 'created_at'];
            if (in_array($sortField, $validSortFields)) {
                $query->orderBy($sortField, $sortOrder === 'desc' ? 'desc' : 'asc');
            }
            
            // 应用分页
            $page = $request->input('page', 1);
            $pageSize = $request->input('pageSize', 10);
            $limits = $query->paginate($pageSize, ['*'], 'page', $page);
            
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'list' => $limits->items() ? collect($limits->items())->map(function($limit) {
                        return $limit->toApiArray();
                    }) : [],
                    'total' => $limits->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('获取账号额度上限列表失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '获取账号额度上限列表失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取单个账号额度上限
     *
     * @param string $id
     * @return JsonResponse
     */
    public function getBalanceLimit(string $id): JsonResponse
    {
        try {
            $limit = AccountBalanceLimit::findOrFail($id);
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $limit->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('获取账号额度上限失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '获取账号额度上限失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 创建账号额度上限
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createBalanceLimit(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'account' => 'required|string|unique:account_balance_limits,account',
                'balanceLimit' => 'required|numeric|min:0',
                'currentBalance' => 'nullable|numeric|min:0',
                'status' => 'nullable|string|in:active,inactive',
            ]);
            
            $limit = AccountBalanceLimit::create([
                'account' => $request->input('account'),
                'balance_limit' => $request->input('balanceLimit'),
                'current_balance' => $request->input('currentBalance', 0),
                'status' => $request->input('status', 'active'),
            ]);
            
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $limit->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('创建账号额度上限失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '创建账号额度上限失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 批量创建账号额度上限
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchCreateBalanceLimits(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'accounts' => 'required|array',
                'accounts.*.account' => 'required|string',
                'accounts.*.balanceLimit' => 'required|numeric|min:0',
                'accounts.*.currentBalance' => 'nullable|numeric|min:0',
                'accounts.*.status' => 'nullable|string|in:active,inactive',
            ]);
            
            $accounts = $request->input('accounts');
            $created = [];
            $failed = [];
            
            DB::beginTransaction();
            
            foreach ($accounts as $accountData) {
                try {
                    // 检查账号是否已存在
                    $exists = AccountBalanceLimit::where('account', $accountData['account'])->exists();
                    if ($exists) {
                        $failed[] = [
                            'account' => $accountData['account'],
                            'reason' => '账号已存在'
                        ];
                        continue;
                    }
                    
                    $limit = AccountBalanceLimit::create([
                        'account' => $accountData['account'],
                        'balance_limit' => $accountData['balanceLimit'],
                        'current_balance' => $accountData['currentBalance'] ?? 0,
                        'status' => $accountData['status'] ?? 'active',
                    ]);
                    
                    $created[] = $limit->toApiArray();
                } catch (\Exception $e) {
                    $failed[] = [
                        'account' => $accountData['account'],
                        'reason' => $e->getMessage()
                    ];
                }
            }
            
            DB::commit();
            
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'created' => $created,
                    'failed' => $failed,
                    'totalCreated' => count($created),
                    'totalFailed' => count($failed),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('批量创建账号额度上限失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '批量创建账号额度上限失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 更新账号额度上限
     *
     * @param string $id
     * @param Request $request
     * @return JsonResponse
     */
    public function updateBalanceLimit(string $id, Request $request): JsonResponse
    {
        try {
            $limit = AccountBalanceLimit::findOrFail($id);
            
            $request->validate([
                'account' => 'nullable|string|unique:account_balance_limits,account,' . $id,
                'balanceLimit' => 'nullable|numeric|min:0',
                'currentBalance' => 'nullable|numeric|min:0',
                'status' => 'nullable|string|in:active,inactive',
            ]);
            
            $limit->update([
                'account' => $request->input('account', $limit->account),
                'balance_limit' => $request->input('balanceLimit', $limit->balance_limit),
                'current_balance' => $request->input('currentBalance', $limit->current_balance),
                'status' => $request->input('status', $limit->status),
            ]);
            
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $limit->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('更新账号额度上限失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '更新账号额度上限失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 重置账号当前余额
     *
     * @param string $id
     * @return JsonResponse
     */
    public function resetBalance(string $id): JsonResponse
    {
        try {
            $limit = AccountBalanceLimit::findOrFail($id);
            $limit->current_balance = 0;
            $limit->save();
            
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $limit->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('重置账号余额失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '重置账号余额失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 更新账号状态
     *
     * @param string $id
     * @param Request $request
     * @return JsonResponse
     */
    public function updateStatus(string $id, Request $request): JsonResponse
    {
        try {
            $limit = AccountBalanceLimit::findOrFail($id);
            
            $request->validate([
                'status' => 'required|string|in:active,inactive',
            ]);
            
            $limit->status = $request->input('status');
            $limit->save();
            
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $limit->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('更新账号状态失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '更新账号状态失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 删除账号额度上限
     *
     * @param string $id
     * @return JsonResponse
     */
    public function deleteBalanceLimit(string $id): JsonResponse
    {
        try {
            $limit = AccountBalanceLimit::findOrFail($id);
            $limit->delete();
            
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            Log::error('删除账号额度上限失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '删除账号额度上限失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }
} 