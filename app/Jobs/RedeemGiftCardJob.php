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
    public int $timeout = 300; // 超时时间(秒) - 增加到5分钟

    protected string $giftCardCode;
    protected string $roomId;
    protected string $cardType;
    protected string $cardForm;
    protected string $batchId;
    protected string $msgId;
    // 业务逻辑错误，不需要堆栈跟踪，直接更新进度为失败，不抛出异常
    protected array $businessErrors = [
        '礼品卡无效',
        '该礼品卡已经被兑换',
        '未找到符合条件的汇率',
        '未找到可用的兑换计划',
        '未找到可用的兑换账号',
        'AlreadyRedeemed',
        'Bad card',
        '查卡失败'
    ];
    // 系统错误，需要堆栈跟踪，抛出异常，队列会重试
    protected array $systemErrors = [
        '系统错误',
        '网络错误',
        '服务器错误',
        '数据库错误',
        'Tap Continue to request re-enablement'
    ];

    const QUEUE_NAME = 'gift-card-redeem';

    public function __construct(
        string $giftCardCode,
        string $roomId,
        string $cardType,
        string $cardForm,
        string $batchId,
        string $msgId = ''
    ) {
        $this->giftCardCode = $giftCardCode;
        $this->roomId = $roomId;
        $this->cardType = $cardType;
        $this->cardForm = $cardForm;
        $this->batchId = $batchId;
        $this->msgId = $msgId;
        // 设置队列名称
        $this->onQueue(self::QUEUE_NAME);
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
     * @throws Throwable
     */
    public function handle(GiftCardService $giftCardService, BatchGiftCardService $batchService): void
    {
        $this->getLogger()->info("开始处理礼品卡兑换任务", [
            'gift_card_code' => $this->giftCardCode,
            'room_id' => $this->roomId,
            'card_type' => $this->cardType,
            'card_form' => $this->cardForm,
            'batch_id' => $this->batchId,
            'msgid' => $this->msgId,
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
                $this->batchId,
                $this->msgId
            );

            // 更新批量任务进度 - 成功
            $batchService->updateProgress($this->batchId, true, $this->giftCardCode, $result);

            $this->getLogger()->info("礼品卡兑换任务完成", [
                'gift_card_code' => $this->giftCardCode,
                'batch_id' => $this->batchId,
                'result' => $result
            ]);

            if(!in_array($this->roomId,['brother-card@api', 'no-send-msg@api'])) {
                send_msg_to_wechat($this->roomId, $result['wechat_msg']);
            }
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

            // 检查是否为业务逻辑错误，如果是则不重试
            if ($this->isBusinessError($e)) {
                $this->getLogger()->info("检测到业务逻辑错误，不进行重试", [
                    'gift_card_code' => $this->giftCardCode,
                    'error' => $e->getMessage()
                ]);
                // 直接更新进度为失败，不抛出异常
                $batchService->updateProgress($this->batchId, false, $this->giftCardCode);
                // 业务逻辑错误直接发送失败消息
                send_msg_to_wechat($this->roomId,"兑换失败\n-------------------------\n".$this->giftCardCode."\n".$e->getMessage());
                return; // 不抛出异常，队列不会重试
            }

            // 如果还有重试机会，抛出异常触发重试
            if ($this->attempts() < $this->tries) {
                throw $e; // 抛出异常，队列会重试
            }

            // 最后一次尝试失败，更新进度（不发送消息，让failed方法处理）
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

        // 尝试获取礼品卡基本信息（如果可能的话）
        $cardInfo = [];
        try {
            // 这里可以尝试调用GiftCardService的check方法来获取基本信息
            // 但要避免再次抛出异常
        } catch (\Exception $ex) {
            // 忽略获取卡信息时的异常
        }

        $batchService->recordError($this->batchId, $this->giftCardCode, $errorMessage, $cardInfo);
    }

    /**
     * 判断是否为系统错误（需要记录堆栈跟踪）
     */
    protected function isSystemError(Throwable $e): bool
    {
        $message = $e->getMessage();

        // 首先检查是否为明确的业务逻辑错误
        foreach ($this->businessErrors as $businessError) {
            if (strpos($message, $businessError) !== false) {
                return false;
            }
        }

        // 然后检查是否为明确的系统错误
        foreach ($this->systemErrors as $systemError) {
            if (strpos($message, $systemError) !== false) {
                return true;
            }
        }

        // 包含"兑换失败:"前缀的错误需要进一步判断
        if (strpos($message, '兑换失败:') === 0) {
            // 检查是否包含需要重试的系统错误关键词
            $retryableErrors = [
                'Tap Continue to request re-enablement',
                '网络',
                '服务器',
                '超时',
                '连接',
                '系统'
            ];

            foreach ($retryableErrors as $retryableError) {
                if (strpos($message, $retryableError) !== false) {
                    return true; // 需要重试的系统错误
                }
            }

            return false; // 其他兑换失败视为业务逻辑错误
        }

        // 其他错误视为系统错误，需要堆栈跟踪
        return true;
    }

    /**
     * 判断是否为业务逻辑错误（不需要重试）
     */
    protected function isBusinessError(Throwable $e): bool
    {
        $message = $e->getMessage();

        // 检查是否为明确的业务逻辑错误
        foreach ($this->businessErrors as $businessError) {
            if (strpos($message, $businessError) !== false) {
                return true;
            }
        }

        // 检查是否为明确的系统错误（需要重试）
        foreach ($this->systemErrors as $systemError) {
            if (strpos($message, $systemError) !== false) {
                return false;
            }
        }

        // 包含"兑换失败:"前缀的错误需要进一步判断
        if (strpos($message, '兑换失败:') === 0) {
            // 检查是否包含需要重试的系统错误关键词
            $retryableErrors = [
                'Tap Continue to request re-enablement',
                '网络',
                '服务器',
                '超时',
                '连接',
                '系统'
            ];

            foreach ($retryableErrors as $retryableError) {
                if (strpos($message, $retryableError) !== false) {
                    return false; // 需要重试的系统错误
                }
            }

            return true; // 其他兑换失败视为业务逻辑错误
        }

        // 其他错误视为系统错误，需要重试
        return false;
    }

    /**
     * 任务最终失败处理
     */
    public function failed(Throwable $exception): void
    {
        // 如果是业务逻辑错误，已经在handle方法中处理过了，不需要重复处理
        if ($this->isBusinessError($exception)) {
            $this->getLogger()->info("业务逻辑错误已在handle方法中处理，跳过failed方法", [
                'gift_card_code' => $this->giftCardCode,
                'error' => $exception->getMessage()
            ]);
            return;
        }

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

        // 发送最终失败消息
        send_msg_to_wechat($this->roomId,"兑换失败\n-------------------------\n".$this->giftCardCode."\n".$exception->getMessage());

        // 确保更新批量任务进度
        try {
            $batchService = app(BatchGiftCardService::class);
            $batchService->updateProgress($this->batchId, false, $this->giftCardCode);

            $finalErrorMessage = sprintf(
                "最终失败 (尝试 %d 次): %s",
                $this->tries,
                $exception->getMessage()
            );

            $batchService->recordError($this->batchId, $this->giftCardCode, $finalErrorMessage, []);

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
