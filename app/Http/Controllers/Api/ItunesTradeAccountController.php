<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ItunesTradeAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ItunesTradeAccountController extends Controller
{
    protected ItunesTradeAccountService $accountService;

    public function __construct(ItunesTradeAccountService $accountService)
    {
        $this->accountService = $accountService;
    }

    /**
     * 获取账号列表
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $params = $request->validate([
                'account'     => 'nullable|string|max:255',
                'country'     => 'nullable|string|max:10',
                'status'      => ['nullable', Rule::in(['completed', 'processing', 'waiting', 'locking', 'banned'])],
                'loginStatus' => ['nullable', Rule::in(['valid', 'invalid'])],
                'uid'         => 'nullable|integer',
                'startTime'   => 'nullable|date',
                'endTime'     => 'nullable|date|after_or_equal:startTime',
                'pageNum'     => 'nullable|integer|min:1',
                'pageSize'    => 'nullable|integer|min:1|max:10000',
                'type'        => 'nullable|string|max:50',
            ]);

            $result = $this->accountService->getAccountsWithPagination($params);

            return response()->json([
                'code'    => 0,
                'message' => 'success',
                'data'    => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('获取账号列表失败: ' . $e->getMessage());
            return response()->json([
                'code'    => 500,
                'message' => '获取账号列表失败',
                'data'    => null,
            ], 500);
        }
    }

    /**
     * 获取账号详情
     */
    public function show(int $id): JsonResponse
    {
        try {
            $result = $this->accountService->getAccountDetail($id);

            if (!$result) {
                return response()->json([
                    'code'    => 404,
                    'message' => '账号不存在',
                    'data'    => null,
                ], 404);
            }

            return response()->json([
                'code'    => 0,
                'message' => 'success',
                'data'    => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('获取账号详情失败: ' . $e->getMessage());
            return response()->json([
                'code'    => 500,
                'message' => '获取账号详情失败',
                'data'    => null,
            ], 500);
        }
    }

    /**
     * 批量导入账号
     */
    public function batchImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code'        => 'required|string|max:10',
            'accounts'            => 'required|array|min:1|max:50',
            'accounts.*.account'  => 'required|string|max:255',
            'accounts.*.password' => 'required|string|max:255',
            'accounts.*.apiUrl'   => 'nullable|string|max:500',
            'type'                => 'required|string|max:50',
        ], [
            'country_code.required'        => '请选择国家',
            'accounts.required'            => '账号不能为空，单次提交最多50条',
            'accounts.min'                 => '至少需要1条账号',
            'accounts.max'                 => '单次最多支持提交50个账号',
            'accounts.*.account.required'  => '账号不能为空',
            'accounts.*.account.max'       => '账号长度最多255位',
            'accounts.*.password.required' => '密码不能为空',
        ]);

        try {

            $result = $this->accountService->batchImportAccounts(
                $validated['country_code'],
                $validated['accounts'],
                $validated['type']
            );

            return response()->json([
                'code'    => 0,
                'message' => '批量导入完成',
                'data'    => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('批量导入账号失败: ' . $e->getMessage());
            return response()->json([
                'code'    => 500,
                'message' => '批量导入失败',
                'data'    => null,
            ], 500);
        }
    }

    /**
     * 更新账号状态
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => ['required', Rule::in(['completed', 'processing', 'waiting', 'banned'])],
            ]);

            $account = $this->accountService->updateAccountStatus($id, $validated['status']);

            if (!$account) {
                return response()->json([
                    'code'    => 404,
                    'message' => '账号不存在',
                    'data'    => null,
                ], 404);
            }

            return response()->json([
                'code'    => 0,
                'message' => '状态更新成功',
                'data'    => $account->toApiArray(),
            ]);

        } catch (\Exception $e) {
            Log::error('更新账号状态失败: ' . $e->getMessage());
            return response()->json([
                'code'    => 500,
                'message' => '状态更新失败：' . $e->getMessage(),
                'data'    => null,
            ], 500);
        }
    }

    /**
     * 删除账号
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $success = $this->accountService->deleteAccount($id);

            if (!$success) {
                return response()->json([
                    'code'    => 404,
                    'message' => '账号不存在',
                    'data'    => null,
                ], 404);
            }


            return response()->json([
                'code'    => 0,
                'message' => '删除成功',
                'data'    => null,
            ]);

        } catch (\Exception $e) {
            Log::error('删除账号失败: ' . $e->getMessage());
            return response()->json([
                'code'    => 500,
                'message' => '删除账号失败',
                'data'    => null,
            ], 500);
        }
    }

    /**
     * 批量删除账号
     */
    public function batchDestroy(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'ids'   => 'required|array|min:1',
                'ids.*' => 'required|integer',
            ]);

            $deletedCount = $this->accountService->batchDeleteAccounts($validated['ids']);

            return response()->json([
                'code'    => 0,
                'message' => '批量删除成功',
                'data'    => ['deleted_count' => $deletedCount],
            ]);

        } catch (\Exception $e) {
            Log::error('批量删除账号失败: ' . $e->getMessage());
            return response()->json([
                'code'    => 500,
                'message' => '批量删除失败',
                'data'    => null,
            ], 500);
        }
    }

    /**
     * 绑定账号到计划
     */
    public function bindToPlan(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'planId' => 'required|integer|exists:itunes_trade_plans,id',
            ]);

            $account = $this->accountService->bindAccountToPlan($id, $validated['planId']);

            if (!$account) {
                return response()->json([
                    'code'    => 404,
                    'message' => '账号不存在',
                    'data'    => null,
                ], 404);
            }

            return response()->json([
                'code'    => 0,
                'message' => '绑定成功',
                'data'    => $account->toApiArray(),
            ]);

        } catch (\Exception $e) {
            Log::error('绑定账号到计划失败: ' . $e->getMessage());
            return response()->json([
                'code'    => 500,
                'message' => '绑定失败',
                'data'    => null,
            ], 500);
        }
    }

    /**
     * 解绑账号计划
     */
    public function unbindFromPlan(int $id): JsonResponse
    {
        try {
            $account = $this->accountService->unbindAccountFromPlan($id);

            if (!$account) {
                return response()->json([
                    'code'    => 404,
                    'message' => '账号不存在',
                    'data'    => null,
                ], 404);
            }

            return response()->json([
                'code'    => 0,
                'message' => '解绑成功',
                'data'    => $account->toApiArray(),
            ]);

        } catch (\Exception $e) {
            Log::error('解绑账号计划失败: ' . $e->getMessage());
            return response()->json([
                'code'    => 500,
                'message' => '解绑失败',
                'data'    => null,
            ], 500);
        }
    }

    /**
     * 更新登录状态
     */
    public function updateLoginStatus(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'loginStatus' => ['required', Rule::in(['valid', 'invalid'])],
            ]);

            $account = $this->accountService->updateLoginStatus($id, $validated['loginStatus']);

            if (!$account) {
                return response()->json([
                    'code'    => 404,
                    'message' => '账号不存在',
                    'data'    => null,
                ], 404);
            }

            return response()->json([
                'code'    => 0,
                'message' => '登录状态更新成功',
                'data'    => $account->toApiArray(),
            ]);

        } catch (\Exception $e) {
            Log::error('更新登录状态失败: ' . $e->getMessage());
            return response()->json([
                'code'    => 500,
                'message' => '登录状态更新失败',
                'data'    => null,
            ], 500);
        }
    }

    /**
     * 获取统计信息
     */
    public function statistics(): JsonResponse
    {
        try {
            $result = $this->accountService->getStatistics();

            return response()->json([
                'code'    => 0,
                'message' => 'success',
                'data'    => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('获取统计信息失败: ' . $e->getMessage());
            return response()->json([
                'code'    => 500,
                'message' => '获取统计信息失败',
                'data'    => null,
            ], 500);
        }
    }

    public function lockStatus(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'username' => 'required|string|max:255',
                'code'     => 'nullable|integer',
                'msg'      => 'nullable|string|max:500',
            ]);



            // 调用Service中的方法处理账号禁用
            $account = $this->accountService->banAccountByUsername(
                $validated['username'],
                $validated['msg'] ?? '',
                $validated['code'] ?? null
            );

            return response()->json([
                'code'    => 0,
                'message' => '账号禁用成功',
                'data'    => null,
            ]);

        } catch (\Exception $e) {
            Log::error('禁用账号失败: ' . $e->getMessage(), [
                'username' => $request->input('username'),
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString()
            ]);

            // 根据异常类型返回不同的错误码
            $errorCode    = 500;
            $errorMessage = '禁用账号失败: ' . $e->getMessage();

            if (str_contains($e->getMessage(), '账号不存在')) {
                $errorCode    = 404;
                $errorMessage = '账号不存在';
            } elseif (str_contains($e->getMessage(), '账号已经被禁用')) {
                $errorCode    = 400;
                $errorMessage = '账号已经被禁用';
            }

            return response()->json([
                'code'    => $errorCode,
                'message' => $errorMessage,
                'data'    => null,
            ], $errorCode);
        }
    }

    /**
     * 获取可用账号
     */
    public function getAvailableAccounts(Request $request): JsonResponse
    {
        try {
            $params = $request->validate([
                'country' => 'nullable|string|max:10',
            ]);

            $accounts = $this->accountService->getAvailableAccounts($params['country'] ?? null);

            $data = $accounts->map(function ($account) {
                return $account->toApiArray();
            })->toArray();

            return response()->json([
                'code'    => 0,
                'message' => 'success',
                'data'    => $data,
            ]);

        } catch (\Exception $e) {
            Log::error('获取可用账号失败: ' . $e->getMessage());
            return response()->json([
                'code'    => 500,
                'message' => '获取可用账号失败',
                'data'    => null,
            ], 500);
        }
    }
}
