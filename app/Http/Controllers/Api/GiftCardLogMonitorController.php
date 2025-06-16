<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GiftCardLogMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GiftCardLogMonitorController extends Controller
{
    protected GiftCardLogMonitorService $logMonitorService;

    public function __construct(GiftCardLogMonitorService $logMonitorService)
    {
        $this->logMonitorService = $logMonitorService;
    }

    /**
     * 获取最新的礼品卡日志
     */
    public function getLatestLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lines' => 'nullable|integer|min:1|max:1000',
        ]);

        try {
            $lines = $validated['lines'] ?? 100;
            $logs = $this->logMonitorService->getLatestLogs($lines);

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'logs' => $logs,
                    'total' => count($logs),
                    'timestamp' => date('Y-m-d H:i:s')
                ]
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
     * 获取日志统计信息
     */
    public function getLogStats(): JsonResponse
    {
        try {
            $stats = $this->logMonitorService->getLogStats();

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '获取统计信息失败: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * 搜索日志
     */
    public function searchLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => 'required|string|min:1|max:255',
            'level' => ['nullable', Rule::in(['ERROR', 'WARNING', 'INFO', 'DEBUG'])],
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        try {
            $results = $this->logMonitorService->searchLogs(
                $validated['keyword'],
                $validated['level'] ?? null,
                $validated['limit'] ?? 100
            );

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'results' => $results,
                    'total' => count($results),
                    'keyword' => $validated['keyword'],
                    'level' => $validated['level'] ?? 'ALL'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '搜索日志失败: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * 获取实时日志流（Server-Sent Events）
     */
    public function getLogStream(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->stream(function () {
            // 设置SSE头部
            echo "data: " . json_encode(['type' => 'connected', 'message' => '连接成功']) . "\n\n";
            ob_flush();
            flush();

            $logFile = storage_path('logs/gift_card_exchange-' . date('Y-m-d') . '.log');
            $lastSize = file_exists($logFile) ? filesize($logFile) : 0;
            $lastPosition = $lastSize;

            while (true) {
                if (!file_exists($logFile)) {
                    sleep(1);
                    continue;
                }

                $currentSize = filesize($logFile);
                
                if ($currentSize > $lastPosition) {
                    // 读取新内容
                    $handle = fopen($logFile, 'r');
                    fseek($handle, $lastPosition);
                    $newContent = fread($handle, $currentSize - $lastPosition);
                    fclose($handle);

                    if (!empty($newContent)) {
                        $lines = explode("\n", trim($newContent));
                        foreach ($lines as $line) {
                            if (!empty(trim($line))) {
                                $logEntry = $this->parseLogLine($line);
                                if ($logEntry) {
                                    echo "data: " . json_encode([
                                        'type' => 'log',
                                        'data' => $logEntry
                                    ]) . "\n\n";
                                    ob_flush();
                                    flush();
                                }
                            }
                        }
                    }
                    
                    $lastPosition = $currentSize;
                }
                
                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // 禁用nginx缓冲
        ]);
    }

    /**
     * 解析日志行（简化版本）
     */
    private function parseLogLine(string $line): ?array
    {
        $pattern = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(\w+): (.+?)(\s+\{.*\})?$/';
        
        if (preg_match($pattern, $line, $matches)) {
            $timestamp = $matches[1];
            $level = strtoupper($matches[2]);
            $message = trim($matches[3]);
            $context = isset($matches[4]) ? trim($matches[4]) : '';

            $contextData = [];
            if (!empty($context)) {
                $contextJson = json_decode($context, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $contextData = $contextJson;
                }
            }

            return [
                'id' => uniqid(),
                'timestamp' => $timestamp,
                'level' => $level,
                'message' => $message,
                'context' => $contextData,
                'color' => $this->getLogColor($level)
            ];
        }

        return null;
    }

    /**
     * 获取日志级别对应的颜色
     */
    private function getLogColor(string $level): string
    {
        $colors = [
            'DEBUG' => 'debug',
            'INFO' => 'success',
            'WARNING' => 'warning',
            'ERROR' => 'error'
        ];

        return $colors[$level] ?? 'default';
    }
} 