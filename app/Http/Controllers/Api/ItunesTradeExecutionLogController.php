<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ItunesTradeExecutionLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ItunesTradeExecutionLogController extends Controller
{
    protected ItunesTradeExecutionLogService $executionLogService;

    public function __construct(ItunesTradeExecutionLogService $executionLogService)
    {
        $this->executionLogService = $executionLogService;
    }

    /**
     * 获取执行记录列表
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $params = $request->validate([
                'page' => 'nullable|integer|min:1',
                'pageNum' => 'nullable|integer|min:1',
                'pageSize' => 'nullable|integer|min:1|max:10000',
                'keyword' => 'nullable|string|max:255',
                'accountId' => 'nullable|integer',
                'account_id' => 'nullable|integer',
                'planId' => 'nullable|integer',
                'plan_id' => 'nullable|integer',
                'rate_id' => 'nullable|integer',
                'executionStatus' => ['nullable', Rule::in(['success', 'failed', 'pending'])],
                'status' => ['nullable', Rule::in(['success', 'failed', 'pending'])],
                'executionType' => 'nullable|string|max:50',
                'country_code' => 'nullable|string|max:10',
                'account_name' => 'nullable|string|max:255',
                'day' => 'nullable|integer|min:1',
                'startTime' => 'nullable|date',
                'start_time' => 'nullable|date',
                'endTime' => 'nullable|date',
                'end_time' => 'nullable|date',
                'room_id' => 'nullable|string|max:50',
            ]);

            // 统一参数名称（将前端的驼峰命名转换为下划线命名）
            $normalizedParams = [
                'pageNum' => $params['pageNum'] ?? $params['page'] ?? 1,
                'pageSize' => $params['pageSize'] ?? 20,
                'keyword' => $params['keyword'] ?? null,
                'account_id' => $params['account_id'] ?? $params['accountId'] ?? null,
                'plan_id' => $params['plan_id'] ?? $params['planId'] ?? null,
                'rate_id' => $params['rate_id'] ?? null,
                'status' => $params['status'] ?? $params['executionStatus'] ?? null,
                'country_code' => $params['country_code'] ?? null,
                'account_name' => $params['account_name'] ?? null,
                'day' => $params['day'] ?? null,
                'start_time' => $params['start_time'] ?? $params['startTime'] ?? null,
                'end_time' => $params['end_time'] ?? $params['endTime'] ?? null,
                'room_id' => $params['room_id'] ?? null,
            ];

            $result = $this->executionLogService->getExecutionLogsWithPagination($normalizedParams);

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $result,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('获取执行记录列表失败: ' . $e->getMessage(), [
                'params' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '获取执行记录列表失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 获取单个执行记录详情
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $result = $this->executionLogService->getExecutionLogDetail($id);

            if (!$result) {
                return response()->json([
                    'code' => 404,
                    'message' => '执行记录不存在',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('获取执行记录详情失败: ' . $e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '获取执行记录详情失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 删除执行记录
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $success = $this->executionLogService->deleteExecutionLog($id);

            if (!$success) {
                return response()->json([
                    'code' => 404,
                    'message' => '执行记录不存在',
                    'data' => null,
                ], 404);
            }

            Log::info('执行记录删除成功', [
                'id' => $id,
                'deleted_by' => auth()->id() ?? 'System'
            ]);

            return response()->json([
                'code' => 0,
                'message' => '删除成功',
                'data' => null,
            ]);

        } catch (\Exception $e) {
            Log::error('删除执行记录失败: ' . $e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '删除执行记录失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 批量删除执行记录
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchDestroy(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'required|integer',
            ]);

            $deletedCount = $this->executionLogService->batchDeleteExecutionLogs($validated['ids']);

            Log::info('执行记录批量删除成功', [
                'ids' => $validated['ids'],
                'deleted_count' => $deletedCount,
                'deleted_by' => auth()->id() ?? 'System'
            ]);

            return response()->json([
                'code' => 0,
                'message' => '批量删除成功',
                'data' => ['deleted_count' => $deletedCount],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('批量删除执行记录失败: ' . $e->getMessage(), [
                'data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '批量删除失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 获取统计信息
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = $this->executionLogService->getStatistics();

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('获取执行记录统计信息失败: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '获取统计信息失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 获取今日统计
     *
     * @return JsonResponse
     */
    public function todayStatistics(): JsonResponse
    {
        try {
            $stats = $this->executionLogService->getTodayStatistics();

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('获取今日执行记录统计失败: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '获取今日统计失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 按账号获取执行记录
     *
     * @param Request $request
     * @param int $accountId
     * @return JsonResponse
     */
    public function byAccount(Request $request, int $accountId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            $limit = $validated['limit'] ?? 50;
            $logs = $this->executionLogService->getLogsByAccount($accountId, $limit);

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $logs,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('按账号获取执行记录失败: ' . $e->getMessage(), [
                'account_id' => $accountId,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '获取执行记录失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 按计划获取执行记录
     *
     * @param Request $request
     * @param int $planId
     * @return JsonResponse
     */
    public function byPlan(Request $request, int $planId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            $limit = $validated['limit'] ?? 50;
            $logs = $this->executionLogService->getLogsByPlan($planId, $limit);

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $logs,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('按计划获取执行记录失败: ' . $e->getMessage(), [
                'plan_id' => $planId,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '获取执行记录失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 导出执行记录
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        try {
            $params = $request->validate([
                'keyword' => 'nullable|string|max:255',
                'accountId' => 'nullable|integer',
                'account_id' => 'nullable|integer',
                'planId' => 'nullable|integer',
                'plan_id' => 'nullable|integer',
                'rate_id' => 'nullable|integer',
                'executionStatus' => ['nullable', Rule::in(['success', 'failed', 'pending'])],
                'status' => ['nullable', Rule::in(['success', 'failed', 'pending'])],
                'country_code' => 'nullable|string|max:10',
                'account_name' => 'nullable|string|max:255',
                'day' => 'nullable|integer|min:1',
                'startTime' => 'nullable|date',
                'start_time' => 'nullable|date',
                'endTime' => 'nullable|date',
                'end_time' => 'nullable|date',
                'room_id' => 'nullable|string|max:50',
            ]);

            // 统一参数名称
            $normalizedParams = [
                'keyword' => $params['keyword'] ?? null,
                'account_id' => $params['account_id'] ?? $params['accountId'] ?? null,
                'plan_id' => $params['plan_id'] ?? $params['planId'] ?? null,
                'rate_id' => $params['rate_id'] ?? null,
                'status' => $params['status'] ?? $params['executionStatus'] ?? null,
                'country_code' => $params['country_code'] ?? null,
                'account_name' => $params['account_name'] ?? null,
                'day' => $params['day'] ?? null,
                'start_time' => $params['start_time'] ?? $params['startTime'] ?? null,
                'end_time' => $params['end_time'] ?? $params['endTime'] ?? null,
                'room_id' => $params['room_id'] ?? null,
            ];

            $filename = 'execution_logs_' . date('Y-m-d_H-i-s') . '.csv';

            return response()->streamDownload(function () use ($normalizedParams) {
                $this->executionLogService->exportExecutionLogs($normalizedParams);
            }, $filename, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // 对于验证错误，返回JSON响应
            return response()->streamDownload(function () use ($e) {
                echo "参数验证失败: " . json_encode($e->errors());
            }, 'error.txt', ['Content-Type' => 'text/plain']);
        } catch (\Exception $e) {
            Log::error('导出执行记录失败: ' . $e->getMessage(), [
                'params' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->streamDownload(function () use ($e) {
                echo "导出失败: " . $e->getMessage();
            }, 'error.txt', ['Content-Type' => 'text/plain']);
        }
    }
}
