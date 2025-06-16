<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TradeMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TradeMonitorController extends Controller
{
    protected TradeMonitorService $monitorService;

    public function __construct(TradeMonitorService $monitorService)
    {
        $this->monitorService = $monitorService;
    }

    /**
     * 获取日志列表
     */
    public function getLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'level' => ['nullable', Rule::in(['ERROR', 'WARNING', 'INFO', 'DEBUG'])],
            'status' => ['nullable', Rule::in(['success', 'failed', 'processing', 'waiting'])],
            'accountId' => 'nullable|string',
            'startTime' => 'nullable|date',
            'endTime' => 'nullable|date',
            'keyword' => 'nullable|string|max:255',
            'pageNum' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $result = $this->monitorService->getLogs($validated);

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '获取日志失败: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * 获取监控统计数据
     */
    public function getStats(): JsonResponse
    {
        try {
            $stats = $this->monitorService->getStats();

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '获取统计数据失败: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * 获取实时状态
     */
    public function getRealtimeStatus(): JsonResponse
    {
        try {
            $status = $this->monitorService->getRealtimeStatus();

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $status
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '获取实时状态失败: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * 清空日志
     */
    public function clearLogs(): JsonResponse
    {
        try {
            $this->monitorService->clearLogs();

            return response()->json([
                'code' => 0,
                'message' => '日志已清空',
                'data' => null
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '清空日志失败: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * 导出日志
     */
    public function exportLogs(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'level' => ['nullable', Rule::in(['ERROR', 'WARNING', 'INFO', 'DEBUG'])],
            'status' => ['nullable', Rule::in(['success', 'failed', 'processing', 'waiting'])],
            'accountId' => 'nullable|string',
            'startTime' => 'nullable|date',
            'endTime' => 'nullable|date',
            'keyword' => 'nullable|string|max:255',
        ]);

        $filename = 'trade_logs_' . date('Y-m-d_H-i-s') . '.csv';

        return response()->streamDownload(function () use ($validated) {
            $this->monitorService->exportLogs($validated);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
} 