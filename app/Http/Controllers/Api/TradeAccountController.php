<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TradeAccount;
use App\Services\TradeAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TradeAccountController extends Controller
{
    protected TradeAccountService $tradeAccountService;

    public function __construct(TradeAccountService $tradeAccountService)
    {
        $this->tradeAccountService = $tradeAccountService;
    }

    /**
     * 获取账号列表
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $params = $request->validate([
                'account' => 'nullable|string',
                'country' => 'nullable|string',
                'status' => ['nullable', Rule::in(['active', 'inactive', 'blocked'])],
                'importedBy' => 'nullable|string',
                'startTime' => 'nullable|date',
                'endTime' => 'nullable|date',
                'pageNum' => 'nullable|integer|min:1',
                'pageSize' => 'nullable|integer|min:1|max:100',
            ]);

            $result = $this->tradeAccountService->getAccountsList($params);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('获取账号列表失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '获取账号列表失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取单个账号详情
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        try {
            $account = TradeAccount::with('countryInfo')->findOrFail($id);
            
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $account->toApiArray(),
            ]);

        } catch (\Exception $e) {
            Log::error('获取账号详情失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '获取账号详情失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 批量导入账号
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchImport(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'country' => 'required|string|max:10',
                'accounts' => 'required|array|min:1',
                'accounts.*.account' => 'required|string|max:255',
                'accounts.*.password' => 'nullable|string|max:500',
                'accounts.*.apiUrl' => 'nullable|url|max:500',
            ]);

            $result = $this->tradeAccountService->batchImportAccounts($data);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $result,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ]);
        } catch (\Exception $e) {
            Log::error('批量导入账号失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '批量导入账号失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 更新账号状态
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => ['required', Rule::in(['active', 'inactive', 'blocked'])],
            ]);

            $account = TradeAccount::findOrFail($id);
            $updatedAccount = $this->tradeAccountService->updateAccountStatus($account, $data['status']);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedAccount->toApiArray(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
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
     * 删除单个账号
     *
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $account = TradeAccount::findOrFail($id);
            
            $accountInfo = [
                'id' => $account->id,
                'account' => $account->account,
                'country' => $account->country
            ];
            
            $account->delete();

            Log::info('Account deleted', [
                'account_info' => $accountInfo,
                'deleted_by' => auth()->user()->name ?? 'System'
            ]);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => null,
            ]);

        } catch (\Exception $e) {
            Log::error('删除账号失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '删除账号失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 批量删除账号
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchDelete(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'required|integer|exists:trade_accounts,id',
            ]);

            $result = $this->tradeAccountService->batchDeleteAccounts($data['ids']);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $result,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ]);
        } catch (\Exception $e) {
            Log::error('批量删除账号失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '批量删除账号失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }
} 