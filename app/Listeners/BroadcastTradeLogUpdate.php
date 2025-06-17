<?php

namespace App\Listeners;

use App\Events\TradeLogCreated;
use App\Services\TradeMonitorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class BroadcastTradeLogUpdate implements ShouldQueue
{
    use InteractsWithQueue;

    protected TradeMonitorService $monitorService;

    /**
     * Create the event listener.
     */
    public function __construct(TradeMonitorService $monitorService)
    {
        $this->monitorService = $monitorService;
    }

    /**
     * Handle the event.
     */
    public function handle(TradeLogCreated $event): void
    {
        try {
            // 格式化日志条目
            $logEntry = $this->formatLogEntry($event->log);

            // 这里可以通过WebSocket广播日志更新
            // 由于WebSocket服务器是独立运行的，我们可以通过Redis发布消息
            $this->publishToWebSocket($logEntry);

        } catch (\Exception $e) {
            Log::error('广播交易日志更新失败', [
                'log_id' => $event->log->id,
                'error' => $e->getMessage()
            ]);
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
            'rateId' => (string)($log->rate_id ?? ''),
            'amount' => $log->amount,
            'status' => $this->mapStatus($log->status),
            'errorMessage' => $log->error_message,
            'metadata' => [
                'gift_card_code' => $log->code,
                'country_code' => $log->country_code,
                'exchanged_amount' => $log->amount,
                'rate_id' => $log->rate_id,
                'batch_id' => $log->batch_id,
                'day' => $log->day,
            ]
        ];
    }

    /**
     * 通过Redis发布WebSocket消息
     */
    private function publishToWebSocket(array $logEntry): void
    {
        $message = json_encode([
            'type' => 'log',
            'data' => $logEntry
        ]);

        // 推送到Redis列表，WebSocket服务器会定期检查
        \Illuminate\Support\Facades\Redis::lpush('websocket-messages', $message);

        // 限制列表长度，避免内存溢出
        \Illuminate\Support\Facades\Redis::ltrim('websocket-messages', 0, 999);
    }

    /**
     * 确定日志级别
     */
    private function determineLogLevel($log): string
    {
        switch ($log->status) {
            case \App\Models\ItunesTradeAccountLog::STATUS_FAILED:
                return 'ERROR';
            case \App\Models\ItunesTradeAccountLog::STATUS_PENDING:
                return 'INFO';
            case \App\Models\ItunesTradeAccountLog::STATUS_SUCCESS:
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
            case \App\Models\ItunesTradeAccountLog::STATUS_SUCCESS:
                return 'success';
            case \App\Models\ItunesTradeAccountLog::STATUS_FAILED:
                return 'failed';
            case \App\Models\ItunesTradeAccountLog::STATUS_PENDING:
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
            case \App\Models\ItunesTradeAccountLog::STATUS_SUCCESS:
                return "账号 {$account} 成功兑换礼品卡 {$log->gift_card_code}，金额 {$log->amount}，获得 {$log->exchanged_amount}";
            case \App\Models\ItunesTradeAccountLog::STATUS_FAILED:
                return "账号 {$account} 兑换礼品卡 {$log->gift_card_code} 失败：{$log->error_message}";
            case \App\Models\ItunesTradeAccountLog::STATUS_PENDING:
                return "账号 {$account} 正在处理礼品卡 {$log->gift_card_code}，金额 {$log->amount}";
            default:
                return "账号 {$account} 礼品卡 {$log->gift_card_code} 状态未知";
        }
    }
}
