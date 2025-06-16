<?php

namespace App\Jobs;

use App\Services\Gift\GiftCardService;
use App\Services\Gift\BatchGiftCardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

class RedeemGiftCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3; // 最大尝试次数
    public int $maxExceptions = 1; // 最大异常次数
    public int $timeout = 120; // 超时时间(秒)

    protected string $giftCardCode;
    protected string $roomId;
    protected string $cardType;
    protected string $cardForm;
    protected string $batchId;

    public function __construct(
        string $giftCardCode,
        string $roomId,
        string $cardType,
        string $cardForm,
        string $batchId
    ) {
        $this->giftCardCode = $giftCardCode;
        $this->roomId = $roomId;
        $this->cardType = $cardType;
        $this->cardForm = $cardForm;
        $this->batchId = $batchId;

        // 设置队列名称
        $this->onQueue('gift-card');
    }

    /**
     * 获取礼品卡兑换专用日志实例
     */
    protected function getLogger(): LoggerInterface
    {
        return Log::channel('gift_card_exchange');
    }

    /**
     * 执行任务
     */
    public function handle(GiftCardService $giftCardService, BatchGiftCardService $batchService): void
    {
        $this->getLogger()->info("开始处理礼品卡兑换任务", [
            'gift_card_code' => $this->giftCardCode,
            'room_id' => $this->roomId,
            'card_type' => $this->cardType,
            'card_form' => $this->cardForm,
            'batch_id' => $this->batchId,
            'attempt' => $this->attempts()
        ]);

        try {
            // 检查批量任务是否已取消
            $batchProgress = $batchService->getBatchProgress($this->batchId);
            if (empty($batchProgress) || $batchProgress['status'] === 'cancelled') {
                $this->getLogger()->info("批量任务已取消，跳过处理", [
                    'gift_card_code' => $this->giftCardCode,
                    'batch_id' => $this->batchId
                ]);
                return;
            }

            // 调用礼品卡服务兑换逻辑
            $result = $giftCardService->redeem(
                $this->giftCardCode,
                $this->roomId,
                $this->cardType,
                $this->cardForm,
                $this->batchId
            );

            // 更新批量任务进度 - 成功
            $batchService->updateProgress($this->batchId, true, $this->giftCardCode, $result);

            $this->getLogger()->info("礼品卡兑换任务完成", [
                'gift_card_code' => $this->giftCardCode,
                'batch_id' => $this->batchId,
                'result' => $result
            ]);

        } catch (Throwable $e) {
            // 根据错误类型决定是否记录堆栈跟踪
            $logData = [
                'gift_card_code' => $this->giftCardCode,
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ];
            
            // 只有系统错误才记录堆栈跟踪
            if ($this->isSystemError($e)) {
                $logData['trace'] = $e->getTraceAsString();
            }
            
            $this->getLogger()->error("礼品卡兑换任务失败", $logData);

            // 记录错误信息
            $this->recordFailure($e, $batchService);

            // 如果还有重试机会，抛出异常触发重试
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            // 最后一次尝试失败，更新进度
            $batchService->updateProgress($this->batchId, false, $this->giftCardCode);
        }
    }

    /**
     * 记录失败信息
     */
    protected function recordFailure(Throwable $e, BatchGiftCardService $batchService): void
    {
        $errorMessage = sprintf(
            "尝试 %d/%d 失败: %s",
            $this->attempts(),
            $this->tries,
            $e->getMessage()
        );

        $batchService->recordError($this->batchId, $this->giftCardCode, $errorMessage);
    }

    /**
     * 判断是否为系统错误（需要记录堆栈跟踪）
     */
    protected function isSystemError(Throwable $e): bool
    {
        $message = $e->getMessage();
        
        // 业务逻辑错误，不需要堆栈跟踪
        $businessErrors = [
            '礼品卡无效',
            '该礼品卡已经被兑换',
            '未找到符合条件的汇率',
            '未找到可用的兑换计划',
            '未找到可用的兑换账号',
            'AlreadyRedeemed',
            'Bad card',
            '查卡失败'
        ];
        
        foreach ($businessErrors as $businessError) {
            if (strpos($message, $businessError) !== false) {
                return false;
            }
        }
        
        // 其他错误视为系统错误，需要堆栈跟踪
        return true;
    }

    /**
     * 任务最终失败处理
     */
    public function failed(Throwable $exception): void
    {
        // 根据错误类型决定是否记录堆栈跟踪
        $logData = [
            'gift_card_code' => $this->giftCardCode,
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ];
        
        // 只有系统错误才记录堆栈跟踪
        if ($this->isSystemError($exception)) {
            $logData['trace'] = $exception->getTraceAsString();
        }
        
        $this->getLogger()->error("礼品卡兑换任务最终失败", $logData);

        // 确保更新批量任务进度
        try {
            $batchService = app(BatchGiftCardService::class);
            $batchService->updateProgress($this->batchId, false, $this->giftCardCode);

            $finalErrorMessage = sprintf(
                "最终失败 (尝试 %d 次): %s",
                $this->tries,
                $exception->getMessage()
            );

            $batchService->recordError($this->batchId, $this->giftCardCode, $finalErrorMessage);

        } catch (Throwable $e) {
            // 这种情况通常是系统错误，记录堆栈跟踪
            $this->getLogger()->error("更新批量任务进度失败", [
                'gift_card_code' => $this->giftCardCode,
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 计算重试延迟时间（指数退避）
     */
    public function backoff(): array
    {
        return [30, 60, 120]; // 30秒、1分钟、2分钟
    }

    /**
     * 获取任务标识符
     */
    public function uniqueId(): string
    {
        return "redeem_gift_card_{$this->batchId}_{$this->giftCardCode}";
    }

    /**
     * 获取任务标签
     */
    public function tags(): array
    {
        return [
            'gift-card-redemption',
            "batch:{$this->batchId}",
            "room:{$this->roomId}",
            "type:{$this->cardType}",
            "form:{$this->cardForm}"
        ];
    }
}
