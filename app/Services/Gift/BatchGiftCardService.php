<?php

namespace App\Services\Gift;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;
use App\Jobs\RedeemGiftCardJob;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class BatchGiftCardService
{
    /**
     * 获取礼品卡兑换专用日志实例
     */
    protected function getLogger(): LoggerInterface
    {
        return Log::channel('gift_card_exchange');
    }

    public function startBatchRedemption(
        array $giftCardCodes,
        string $roomId,
        string $cardType,
        string $cardForm
    ): string {
        $batchId = Str::uuid()->toString();
        
        // 初始化批量任务状态
        $this->initializeBatch($batchId, count($giftCardCodes), $roomId, $cardType, $cardForm);

        // 分发单个任务到Redis队列
        try {
            foreach ($giftCardCodes as $code) {
                $job = new RedeemGiftCardJob($code, $roomId, $cardType, $cardForm, $batchId);
                Queue::push($job);
            }

            $this->getLogger()->info("批量兑换任务已启动", [
                'batch_id' => $batchId,
                'total_cards' => count($giftCardCodes),
                'room_id' => $roomId,
                'card_type' => $cardType,
                'card_form' => $cardForm
            ]);

        } catch (\Throwable $e) {
            $this->getLogger()->error("批量兑换任务启动失败", [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->markBatchAsFailed($batchId);
            throw $e;
        }

        return $batchId;
    }

    protected function initializeBatch(
        string $batchId,
        int $total,
        string $roomId,
        string $cardType,
        string $cardForm
    ): void {
        $now = now()->toISOString();

        Redis::hmset("batch:{$batchId}", [
            'total' => $total,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'status' => 'processing',
            'room_id' => $roomId,
            'card_type' => $cardType,
            'card_form' => $cardForm,
            'created_at' => $now,
            'updated_at' => $now
        ]);

        // 设置过期时间（7天）
        Redis::expire("batch:{$batchId}", 7 * 24 * 3600);
        Redis::expire("batch:{$batchId}:errors", 7 * 24 * 3600);
        Redis::expire("batch:{$batchId}:results", 7 * 24 * 3600);
    }

    protected function markBatchAsCompleted(string $batchId): void
    {
        Redis::hmset("batch:{$batchId}", [
            'status' => 'completed',
            'updated_at' => now()->toISOString()
        ]);

        $this->getLogger()->info("批量兑换任务完成", ['batch_id' => $batchId]);
    }

    protected function markBatchAsFailed(string $batchId): void
    {
        Redis::hmset("batch:{$batchId}", [
            'status' => 'failed',
            'updated_at' => now()->toISOString()
        ]);

        $this->getLogger()->error("批量兑换任务失败", ['batch_id' => $batchId]);
    }

    public function getBatchProgress(string $batchId): array
    {
        return Redis::hgetall("batch:{$batchId}");
    }

    public function getBatchErrors(string $batchId): array
    {
        return Redis::hgetall("batch:{$batchId}:errors");
    }

    public function getBatchResults(string $batchId): array
    {
        return Redis::hgetall("batch:{$batchId}:results");
    }

    public function cancelBatch(string $batchId): bool
    {
        $batch = Redis::hgetall("batch:{$batchId}");

        if (empty($batch)) {
            return false;
        }

        if (in_array($batch['status'], ['completed', 'failed', 'cancelled'])) {
            return false; // 已完成或已取消的任务无法再次取消
        }

        Redis::hmset("batch:{$batchId}", [
            'status' => 'cancelled',
            'updated_at' => now()->toISOString()
        ]);

        $this->getLogger()->info("批量兑换任务已取消", ['batch_id' => $batchId]);

        return true;
    }

    /**
     * 更新单个任务进度
     */
    public function updateProgress(string $batchId, bool $success, string $giftCardCode, array $result = []): void
    {
        // 增加处理计数
        Redis::hincrby("batch:{$batchId}", 'processed', 1);

        if ($success) {
            Redis::hincrby("batch:{$batchId}", 'success', 1);

            // 记录成功结果
            if (!empty($result)) {
                Redis::hset("batch:{$batchId}:results", $giftCardCode, json_encode($result));
            }
        } else {
            Redis::hincrby("batch:{$batchId}", 'failed', 1);
        }

        // 更新时间戳
        Redis::hset("batch:{$batchId}", 'updated_at', now()->toISOString());

        // 检查是否所有任务都已完成
        $batch = Redis::hgetall("batch:{$batchId}");
        if ($batch && isset($batch['total'], $batch['processed'])) {
            $total = (int)$batch['total'];
            $processed = (int)$batch['processed'];
            
            if ($processed >= $total && $batch['status'] === 'processing') {
                $this->markBatchAsCompleted($batchId);
            }
        }
    }

    /**
     * 记录错误信息
     */
    public function recordError(string $batchId, string $giftCardCode, string $error): void
    {
        Redis::hset("batch:{$batchId}:errors", $giftCardCode, $error);
    }

    /**
     * 清理过期的批量任务数据
     */
    public function cleanupExpiredBatches(): int
    {
        $pattern = "batch:*";
        $keys = Redis::keys($pattern);
        $cleaned = 0;

        foreach ($keys as $key) {
            if (Redis::ttl($key) <= 0) {
                Redis::del($key);
                $cleaned++;
            }
        }

        $this->getLogger()->info("清理过期批量任务", ['cleaned_count' => $cleaned]);

        return $cleaned;
    }
}
