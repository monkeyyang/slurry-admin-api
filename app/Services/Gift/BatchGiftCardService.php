<?php

namespace App\Services\Gift;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;
use App\Jobs\RedeemGiftCardJob;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

class BatchGiftCardService
{
    // 批量兑换任务的属性
    protected array $giftCardCodes = [];
    protected string $roomId = '';
    protected string $cardType = '';
    protected string $cardForm = '';
    protected string $msgId = '';
    protected string $wxId = '';
    protected array $additionalParams = [];

    /**
     * 获取礼品卡兑换专用日志实例
     */
    protected function getLogger(): LoggerInterface
    {
        return Log::channel('gift_card_exchange');
    }

    /**
     * 设置礼品卡码列表
     */
    public function setGiftCardCodes(array $codes): self
    {
        $this->giftCardCodes = $codes;
        return $this;
    }

    /**
     * 设置房间ID
     */
    public function setRoomId(string $roomId): self
    {
        $this->roomId = $roomId;
        return $this;
    }

    /**
     * 设置卡类型
     */
    public function setCardType(string $cardType): self
    {
        $this->cardType = $cardType;
        return $this;
    }

    /**
     * 设置卡形式
     */
    public function setCardForm(string $cardForm): self
    {
        $this->cardForm = $cardForm;
        return $this;
    }

    /**
     * 设置消息ID
     */
    public function setMsgId(string $msgId): self
    {
        $this->msgId = $msgId;
        return $this;
    }

    /**
     * 设置微信ID
     */
    public function setWxId(string $wxId): self
    {
        $this->wxId = $wxId;
        return $this;
    }

    /**
     * 设置额外参数
     */
    public function setAdditionalParam(string $key, $value): self
    {
        $this->additionalParams[$key] = $value;
        return $this;
    }

    /**
     * 批量设置额外参数
     */
    public function setAdditionalParams(array $params): self
    {
        $this->additionalParams = array_merge($this->additionalParams, $params);
        return $this;
    }

    /**
     * 获取额外参数
     */
    public function getAdditionalParam(string $key, $default = null)
    {
        return $this->additionalParams[$key] ?? $default;
    }

    /**
     * 重置所有属性
     */
    public function reset(): self
    {
        $this->giftCardCodes = [];
        $this->roomId = '';
        $this->cardType = '';
        $this->cardForm = '';
        $this->msgId = '';
        $this->wxId = '';
        $this->additionalParams = [];
        return $this;
    }

    /**
     * 验证必要参数
     */
    protected function validateParams(): void
    {
        if (empty($this->giftCardCodes)) {
            throw new \InvalidArgumentException('礼品卡列表不能为空');
        }
        if (empty($this->roomId)) {
            throw new \InvalidArgumentException('群聊ID不能为空');
        }
        if (empty($this->cardType)) {
            throw new \InvalidArgumentException('卡类型不能为空');
        }
        if (empty($this->cardForm)) {
            throw new \InvalidArgumentException('卡形式不能为空');
        }
    }

    /**
     * 开始批量兑换任务
     * @throws Throwable
     */
    public function startBatchRedemption(): string
    {
        // 验证参数
        $this->validateParams();

        $batchId = Str::uuid()->toString();

        // 初始化批量任务状态
        $this->initializeBatch($batchId, count($this->giftCardCodes));

        // 分发单个任务到Redis队列
        try {
            foreach ($this->giftCardCodes as $code) {
                $job = new RedeemGiftCardJob();

                // 设置任务属性
                $job->setGiftCardCode($code)
                    ->setRoomId($this->roomId)
                    ->setCardType($this->cardType)
                    ->setCardForm($this->cardForm)
                    ->setBatchId($batchId)
                    ->setMsgId($this->msgId)
                    ->setWxId($this->wxId)
                    ->setAdditionalParams($this->additionalParams);

                dispatch($job);
            }

            $this->getLogger()->info("批量兑换任务已启动", [
                'batch_id' => $batchId,
                'cards' => $this->giftCardCodes,
                'total_cards' => count($this->giftCardCodes),
                'room_id' => $this->roomId,
                'card_type' => $this->cardType,
                'card_form' => $this->cardForm,
                'additional_params' => $this->additionalParams
            ]);

        } catch (Throwable $e) {
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

    /**
     * 兼容旧版本的方法（已废弃，建议使用新的属性设置方式）
     * @deprecated 使用属性设置方法替代
     */
    public function startBatchRedemptionLegacy(
        array $giftCardCodes,
        string $roomId,
        string $cardType,
        string $cardForm,
        string $msgid = '',
    ): string {
        return $this->setGiftCardCodes($giftCardCodes)
            ->setRoomId($roomId)
            ->setCardType($cardType)
            ->setCardForm($cardForm)
            ->setMsgId($msgid)
            ->startBatchRedemption();
    }

    /**
     * 初始化批量任务
     */
    private function initializeBatch(string $batchId, int $totalCards): void
    {
        Redis::hmset("batch:{$batchId}", [
            'status' => 'processing',
            'total' => $totalCards,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'room_id' => $this->roomId,
            'card_type' => $this->cardType,
            'card_form' => $this->cardForm,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString()
        ]);

        // 设置过期时间为24小时
        Redis::expire("batch:{$batchId}", 86400);
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

            // 记录成功结果 - 包含详细信息
            if (!empty($result)) {
                $successData = [
                    'code' => $giftCardCode,
                    'country_code' => $result['country_code'] ?? null,
                    'original_amount' => $result['original_amount'] ?? null,
                    'amount' => $result['amount'] ?? null,
                    'currency' => $result['currency'] ?? null,
                    'account_id' => $result['account_id'] ?? null,
                    'account_balance' => $result['account_balance'] ?? null,
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'exchange_time' => $result['exchange_time'] ?? now()->toISOString(),
                    'rate_id' => $result['rate_id'] ?? null,
                    'plan_id' => $result['plan_id'] ?? null,
                ];
                Redis::hset("batch:{$batchId}:success", $giftCardCode, json_encode($successData));
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
     * 记录错误信息 - 包含详细的失败信息
     */
    public function recordError(string $batchId, string $giftCardCode, string $error, array $cardInfo = []): void
    {
        $errorData = [
            'gift_card_code' => $giftCardCode,
            'error_message' => $error,
            'country_code' => $cardInfo['country_code'] ?? null,
            'amount' => $cardInfo['amount'] ?? null,
            'currency' => $cardInfo['currency'] ?? null,
            'failed_time' => now()->toISOString(),
        ];

        Redis::hset("batch:{$batchId}:failed", $giftCardCode, json_encode($errorData));

        // 保持原有的简单错误记录以兼容现有代码
        Redis::hset("batch:{$batchId}:errors", $giftCardCode, $error);
    }

    /**
     * 获取批量任务的成功结果详情
     */
    public function getBatchSuccessResults(string $batchId): array
    {
        $results = Redis::hgetall("batch:{$batchId}:success");
        $successResults = [];

        foreach ($results as $giftCardCode => $resultJson) {
            $resultData = json_decode($resultJson, true);
            if ($resultData) {
                $successResults[] = $resultData;
            }
        }

        return $successResults;
    }

    /**
     * 获取批量任务的失败结果详情
     */
    public function getBatchFailedResults(string $batchId): array
    {
        $results = Redis::hgetall("batch:{$batchId}:failed");
        $failedResults = [];

        foreach ($results as $giftCardCode => $resultJson) {
            $resultData = json_decode($resultJson, true);
            if ($resultData) {
                $failedResults[] = $resultData;
            }
        }

        return $failedResults;
    }

    /**
     * 获取批量任务的完整结果摘要
     */
    public function getBatchSummary(string $batchId): array
    {
        $progress = $this->getBatchProgress($batchId);

        if (empty($progress)) {
            return [];
        }

        $successResults = $this->getBatchSuccessResults($batchId);
        $failedResults = $this->getBatchFailedResults($batchId);

        return [
            'batch_id' => $batchId,
            'status' => $progress['status'],
            'total' => (int)($progress['total'] ?? 0),
            'processed' => (int)($progress['processed'] ?? 0),
            'success_count' => (int)($progress['success'] ?? 0),
            'failed_count' => (int)($progress['failed'] ?? 0),
            'progress_percentage' => $progress['total'] > 0 ? round(($progress['processed'] / $progress['total']) * 100, 2) : 0,
            'created_at' => $progress['created_at'] ?? null,
            'updated_at' => $progress['updated_at'] ?? null,
            'success_results' => $successResults,
            'failed_results' => $failedResults,
        ];
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
