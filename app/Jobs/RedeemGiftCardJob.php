<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Gift\GiftCardService;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class RedeemGiftCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;
    public $backoff = [60, 120, 300];

    // 任务相关属性
    protected string $giftCardCode = '';
    protected string $roomId = '';
    protected string $cardType = '';
    protected string $cardForm = '';
    protected string $batchId = '';
    protected string $msgId = '';
    protected string $wxId = '';
    protected array $additionalParams = [];

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('gift-card-redeem');
    }

    /**
     * 设置礼品卡码
     */
    public function setGiftCardCode(string $code): self
    {
        $this->giftCardCode = $code;
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
     * 设置批次ID
     */
    public function setBatchId(string $batchId): self
    {
        $this->batchId = $batchId;
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
     * 获取礼品卡兑换专用日志实例
     */
    protected function getLogger(): LoggerInterface
    {
        return Log::channel('gift_card_exchange');
    }

    /**
     * Execute the job.
     */
    public function handle(GiftCardService $giftCardService): void
    {
        $startTime = microtime(true);

        $this->getLogger()->info("开始处理礼品卡兑换任务", [
            'job_id' => $this->job->getJobId(),
            'card_code' => $this->giftCardCode,
            'room_id' => $this->roomId,
            'card_type' => $this->cardType,
            'card_form' => $this->cardForm,
            'batch_id' => $this->batchId,
            'msg_id' => $this->msgId,
            'wx_id' => $this->wxId,
            'additional_params' => $this->additionalParams,
            'attempt' => $this->attempts()
        ]);

        try {
            // 设置GiftCardService的属性
            $giftCardService->setGiftCardCode($this->giftCardCode)
                ->setRoomId($this->roomId)
                ->setCardType($this->cardType)
                ->setCardForm($this->cardForm)
                ->setBatchId($this->batchId)
                ->setMsgId($this->msgId)
                ->setWxId($this->wxId)
                ->setAdditionalParams($this->additionalParams);

            // 执行兑换
            $result = $giftCardService->redeemGiftCard();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->getLogger()->info("礼品卡兑换任务完成", [
                'job_id' => $this->job->getJobId(),
                'card_code' => $this->giftCardCode,
                'room_id' => $this->roomId,
                'batch_id' => $this->batchId,
                'result' => $result,
                'execution_time_ms' => $executionTime,
                'attempt' => $this->attempts()
            ]);

        } catch (\Throwable $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->getLogger()->error("礼品卡兑换任务失败", [
                'job_id' => $this->job->getJobId(),
                'card_code' => $this->giftCardCode,
                'room_id' => $this->roomId,
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTime,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'trace' => $e->getTraceAsString()
            ]);

            // 重新抛出异常以触发重试机制
            throw $e;
        }
    }

    /**
     * 兼容旧版本的构造函数（已废弃，建议使用新的属性设置方式）
     * @deprecated 使用属性设置方法替代
     */
    public static function createLegacy(
        string $giftCardCode,
        string $roomId,
        string $cardType,
        string $cardForm,
        string $batchId = '',
        string $msgid = ''
    ): self {
        return (new self())
            ->setGiftCardCode($giftCardCode)
            ->setRoomId($roomId)
            ->setCardType($cardType)
            ->setCardForm($cardForm)
            ->setBatchId($batchId)
            ->setMsgId($msgid);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->getLogger()->error("礼品卡兑换任务最终失败", [
            'job_id' => $this->job?->getJobId(),
            'card_code' => $this->giftCardCode,
            'room_id' => $this->roomId,
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'max_tries' => $this->tries,
            'trace' => $exception->getTraceAsString()
        ]);
    }
} 