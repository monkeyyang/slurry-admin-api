<?php

namespace App\Services;

use App\Models\ItunesTradeAccountLog;
use App\Models\ItunesTradeAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TradeMonitorService
{
    /**
     * 获取日志列表
     */
    public function getLogs(array $params): array
    {
        $pageNum = $params['pageNum'] ?? 1;
        $pageSize = $params['pageSize'] ?? 20;

        $query = ItunesTradeAccountLog::with(['account', 'plan', 'rate'])
            ->orderBy('created_at', 'desc');

        // 状态筛选
        if (!empty($params['status'])) {
            $statusMap = [
                'success' => ItunesTradeAccountLog::STATUS_SUCCESS,
                'failed' => ItunesTradeAccountLog::STATUS_FAILED,
                'processing' => ItunesTradeAccountLog::STATUS_PENDING,
                'waiting' => ItunesTradeAccountLog::STATUS_PENDING,
            ];
            
            if (isset($statusMap[$params['status']])) {
                $query->where('status', $statusMap[$params['status']]);
            }
        }

        // 账号筛选
        if (!empty($params['accountId'])) {
            $query->where('account_id', $params['accountId']);
        }

        // 时间范围筛选
        if (!empty($params['startTime'])) {
            $query->where('created_at', '>=', $params['startTime']);
        }
        if (!empty($params['endTime'])) {
            $query->where('created_at', '<=', $params['endTime']);
        }

        // 关键词搜索
        if (!empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('gift_card_code', 'like', '%' . $params['keyword'] . '%')
                  ->orWhere('transaction_id', 'like', '%' . $params['keyword'] . '%')
                  ->orWhere('error_message', 'like', '%' . $params['keyword'] . '%');
            });
        }

        $total = $query->count();
        $logs = $query->offset(($pageNum - 1) * $pageSize)
                     ->limit($pageSize)
                     ->get();

        $data = $logs->map(function ($log) {
            return $this->formatLogEntry($log);
        });

        return [
            'data' => $data,
            'total' => $total,
            'pageNum' => $pageNum,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * 获取统计数据
     */
    public function getStats(): array
    {
        $today = Carbon::today();
        $todayEnd = Carbon::today()->endOfDay();

        // 总体统计
        $totalExchanges = ItunesTradeAccountLog::count();
        $successCount = ItunesTradeAccountLog::where('status', ItunesTradeAccountLog::STATUS_SUCCESS)->count();
        $failedCount = ItunesTradeAccountLog::where('status', ItunesTradeAccountLog::STATUS_FAILED)->count();
        $processingCount = ItunesTradeAccountLog::where('status', ItunesTradeAccountLog::STATUS_PENDING)->count();

        // 今日统计
        $todayExchanges = ItunesTradeAccountLog::whereBetween('created_at', [$today, $todayEnd])->count();
        $todaySuccessCount = ItunesTradeAccountLog::where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->whereBetween('created_at', [$today, $todayEnd])
            ->count();
        $todayFailedCount = ItunesTradeAccountLog::where('status', ItunesTradeAccountLog::STATUS_FAILED)
            ->whereBetween('created_at', [$today, $todayEnd])
            ->count();

        $successRate = $totalExchanges > 0 ? round(($successCount / $totalExchanges) * 100, 2) : 0;

        return [
            'totalExchanges' => $totalExchanges,
            'successCount' => $successCount,
            'failedCount' => $failedCount,
            'processingCount' => $processingCount,
            'successRate' => $successRate,
            'todayExchanges' => $todayExchanges,
            'todaySuccessCount' => $todaySuccessCount,
            'todayFailedCount' => $todayFailedCount,
        ];
    }

    /**
     * 获取实时状态
     */
    public function getRealtimeStatus(): array
    {
        // 检查是否有正在处理的任务
        $currentTask = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->with('plan')
            ->first();

        // 获取队列中的任务数量
        $queueCount = $this->getQueueCount();

        $isRunning = $currentTask !== null || $queueCount > 0;

        $result = [
            'isRunning' => $isRunning,
            'queueCount' => $queueCount,
            'lastUpdateTime' => now()->toISOString(),
        ];

        if ($currentTask) {
            $result['currentTask'] = [
                'accountId' => (string)$currentTask->id,
                'account' => $currentTask->username ?? $currentTask->email ?? 'Unknown',
                'planId' => (string)($currentTask->plan_id ?? 0),
                'currentDay' => $currentTask->current_plan_day ?? 1,
                'startTime' => $currentTask->updated_at->toISOString(),
            ];
        }

        return $result;
    }

    /**
     * 清空日志
     */
    public function clearLogs(): void
    {
        ItunesTradeAccountLog::truncate();
        Log::channel('gift_card_exchange')->info('交易日志已被清空');
    }

    /**
     * 导出日志
     */
    public function exportLogs(array $params): void
    {
        $query = ItunesTradeAccountLog::with(['account', 'plan', 'rate'])
            ->orderBy('created_at', 'desc');

        // 应用筛选条件
        $this->applyFilters($query, $params);

        // 输出CSV头部
        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'ID', '时间戳', '级别', '消息', '账号ID', '计划ID', '金额', '状态', 
            '错误信息', '礼品卡码', '交易ID', '国家代码', '兑换金额'
        ]);

        // 分批处理数据
        $query->chunk(1000, function ($logs) use ($output) {
            foreach ($logs as $log) {
                $logEntry = $this->formatLogEntry($log);
                fputcsv($output, [
                    $logEntry['id'],
                    $logEntry['timestamp'],
                    $logEntry['level'],
                    $logEntry['message'],
                    $logEntry['accountId'] ?? '',
                    $logEntry['planId'] ?? '',
                    $logEntry['amount'] ?? '',
                    $logEntry['status'] ?? '',
                    $logEntry['errorMessage'] ?? '',
                    $log->gift_card_code ?? '',
                    $log->transaction_id ?? '',
                    $log->country_code ?? '',
                    $log->exchanged_amount ?? ''
                ]);
            }
        });

        fclose($output);
    }

    /**
     * 应用筛选条件
     */
    private function applyFilters($query, array $params): void
    {
        if (!empty($params['status'])) {
            $statusMap = [
                'success' => ItunesTradeAccountLog::STATUS_SUCCESS,
                'failed' => ItunesTradeAccountLog::STATUS_FAILED,
                'processing' => ItunesTradeAccountLog::STATUS_PENDING,
                'waiting' => ItunesTradeAccountLog::STATUS_PENDING,
            ];
            
            if (isset($statusMap[$params['status']])) {
                $query->where('status', $statusMap[$params['status']]);
            }
        }

        if (!empty($params['accountId'])) {
            $query->where('account_id', $params['accountId']);
        }

        if (!empty($params['startTime'])) {
            $query->where('created_at', '>=', $params['startTime']);
        }
        if (!empty($params['endTime'])) {
            $query->where('created_at', '<=', $params['endTime']);
        }

        if (!empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('gift_card_code', 'like', '%' . $params['keyword'] . '%')
                  ->orWhere('transaction_id', 'like', '%' . $params['keyword'] . '%')
                  ->orWhere('error_message', 'like', '%' . $params['keyword'] . '%');
            });
        }
    }

    /**
     * 格式化日志条目
     */
    private function formatLogEntry($log): array
    {
        return [
            'id' => (string)$log->id,
            'timestamp' => $log->created_at->toISOString(),
            'level' => $this->determineLogLevel($log),
            'message' => $this->generateLogMessage($log),
            'accountId' => (string)$log->account_id,
            'planId' => (string)($log->plan_id ?? ''),
            'amount' => $log->amount,
            'status' => $this->mapStatus($log->status),
            'errorMessage' => $log->error_message,
            'metadata' => [
                'gift_card_code' => $log->gift_card_code,
                'transaction_id' => $log->transaction_id,
                'country_code' => $log->country_code,
                'exchanged_amount' => $log->exchanged_amount,
                'rate_id' => $log->rate_id,
                'batch_id' => $log->batch_id,
                'day' => $log->day,
                'account_username' => $log->account->username ?? null,
                'plan_name' => $log->plan->name ?? null,
            ]
        ];
    }

    /**
     * 确定日志级别
     */
    private function determineLogLevel($log): string
    {
        switch ($log->status) {
            case ItunesTradeAccountLog::STATUS_FAILED:
                return 'ERROR';
            case ItunesTradeAccountLog::STATUS_PENDING:
                return 'INFO';
            case ItunesTradeAccountLog::STATUS_SUCCESS:
                return 'INFO';
            default:
                return 'DEBUG';
        }
    }

    /**
     * 映射状态
     */
    private function mapStatus($status): string
    {
        switch ($status) {
            case ItunesTradeAccountLog::STATUS_SUCCESS:
                return 'success';
            case ItunesTradeAccountLog::STATUS_FAILED:
                return 'failed';
            case ItunesTradeAccountLog::STATUS_PENDING:
                return 'processing';
            default:
                return 'waiting';
        }
    }

    /**
     * 生成日志消息
     */
    private function generateLogMessage($log): string
    {
        $account = $log->account->username ?? $log->account->email ?? "账号{$log->account_id}";
        
        switch ($log->status) {
            case ItunesTradeAccountLog::STATUS_SUCCESS:
                return "账号 {$account} 成功兑换礼品卡 {$log->gift_card_code}，金额 {$log->amount}，获得 {$log->exchanged_amount}";
            case ItunesTradeAccountLog::STATUS_FAILED:
                return "账号 {$account} 兑换礼品卡 {$log->gift_card_code} 失败：{$log->error_message}";
            case ItunesTradeAccountLog::STATUS_PENDING:
                return "账号 {$account} 正在处理礼品卡 {$log->gift_card_code}，金额 {$log->amount}";
            default:
                return "账号 {$account} 礼品卡 {$log->gift_card_code} 状态未知";
        }
    }

    /**
     * 获取队列任务数量
     */
    private function getQueueCount(): int
    {
        try {
            $queueName = config('queue.connections.redis.queue', 'default');
            return Redis::llen("queues:{$queueName}");
        } catch (\Exception $e) {
            Log::warning('获取队列数量失败: ' . $e->getMessage());
            return 0;
        }
    }
} 