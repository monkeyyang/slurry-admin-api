<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Gift\GiftCardService;
use App\Services\Gift\BatchGiftCardService;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

class RedeemGiftCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $timeout = 300;
    public int   $tries   = 3;
    public array $backoff = [1, 2, 3];

    // 任务相关属性
    protected string $giftCardCode     = '';
    protected string $roomId           = '';
    protected string $cardType         = '';
    protected string $cardForm         = '';
    protected string $batchId          = '';
    protected string $msgId            = '';
    protected string $wxId             = '';
    protected array  $additionalParams = [];

    // 业务逻辑错误，不需要堆栈跟踪，直接更新进度为失败，不抛出异常
    // 与 GiftCardService::BUSINESS_ERRORS 保持一致
    private const BUSINESS_ERRORS = [
        '礼品卡无效',
        '该礼品卡已经被兑换',
        '未找到符合条件的汇率',
        '未找到可用的兑换计划',
        '未找到可用的兑换账号',
        '没有找到合适的可执行计划',
        '所有账号已达额度上限',
        'AlreadyRedeemed',
        'Bad card',
        '查卡失败',
        '礼品卡已存在处理记录',
        '正在处理中，请勿重复提交',
        '账号余额不足',
        '超出每日限额',
        '超出总限额',
        '不符合倍数要求'
    ];

    // 系统错误，需要重试
    private const SYSTEM_ERRORS = [
        '网络错误',
        '连接超时',
        '服务器错误',
        '数据库连接失败',
        '系统繁忙',
        'Connection refused',
        'timeout',
        'Server Error'
    ];

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('gift-card-redeem');
    }

    /**
     * 设置礼品卡
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
     * 执行队列
     * @throws Throwable
     */
    public function handle(GiftCardService $giftCardService, BatchGiftCardService $batchService): void
    {
        $startTime = microtime(true);

        $this->getLogger()->info("开始处理礼品卡兑换任务", [
            'job_id'            => $this->job->getJobId(),
            'card_code'         => $this->giftCardCode,
            'room_id'           => $this->roomId,
            'card_type'         => $this->cardType,
            'card_form'         => $this->cardForm,
            'batch_id'          => $this->batchId,
            'msg_id'            => $this->msgId,
            'wx_id'             => $this->wxId,
            'additional_params' => $this->additionalParams,
            'attempt'           => $this->attempts()
        ]);

        try {
            // 检查批量任务是否已取消
            if (!empty($this->batchId)) {
                $batchProgress = $batchService->getBatchProgress($this->batchId);
                if (empty($batchProgress) || $batchProgress['status'] === 'cancelled') {
                    $this->getLogger()->info("批量任务已取消，跳过处理", [
                        'gift_card_code' => $this->giftCardCode,
                        'batch_id'       => $this->batchId
                    ]);
                    return;
                }
            }

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
            // 获取执行时长
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->getLogger()->info("礼品卡兑换任务完成", [
                'job_id'            => $this->job->getJobId(),
                'card_code'         => $this->giftCardCode,
                'room_id'           => $this->roomId,
                'batch_id'          => $this->batchId,
                'result'            => $result,
                'execution_time_ms' => $executionTime,
                'attempt'           => $this->attempts()
            ]);

            // 发送微信消息
            if (!in_array($this->roomId, ['brother-card@api', 'no-send-msg@api'])) {
                send_msg_to_wechat($this->roomId, $result['wechat_msg']);
            }

            // 更新批量任务进度 - 成功
            if (!empty($this->batchId)) {
                $batchService->updateProgress($this->batchId, true, $this->giftCardCode, $result);
            }

        } catch (Throwable $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // 记录失败信息
            $this->recordFailure($e, $batchService);

            // 检查是否为业务逻辑错误
            if ($this->isBusinessError($e)) {
                $this->getLogger()->warning("检测到业务逻辑错误，不进行重试", [
                    'job_id'            => $this->job->getJobId(),
                    'card_code'         => $this->giftCardCode,
                    'batch_id'          => $this->batchId,
                    'error'             => $e->getMessage(),
                    'execution_time_ms' => $executionTime,
                    'attempt'           => $this->attempts()
                ]);

                // 发送失败消息
                if (!in_array($this->roomId, ['brother-card@api', 'no-send-msg@api'])) {
                    send_msg_to_wechat($this->roomId, "兑换失败\n-------------------------\n" . $this->giftCardCode . "\n" . $e->getMessage());
                }

                // 更新批量任务进度 - 失败
                if (!empty($this->batchId)) {
                    $batchService->updateProgress($this->batchId, false, $this->giftCardCode, null, $e->getMessage());
                }

                // 不抛出异常，避免重试
                return;
            }

            // 系统错误，记录详细信息并重新抛出异常以触发重试
            $this->getLogger()->error("礼品卡兑换任务失败（系统错误）", [
                'job_id'            => $this->job->getJobId(),
                'card_code'         => $this->giftCardCode,
                'room_id'           => $this->roomId,
                'batch_id'          => $this->batchId,
                'error'             => $e->getMessage(),
                'execution_time_ms' => $executionTime,
                'attempt'           => $this->attempts(),
                'max_tries'         => $this->tries,
                'trace'             => $e->getTraceAsString()
            ]);

            // 更新批量任务进度 - 失败（但可能会重试）
            if (!empty($this->batchId)) {
                $batchService->updateProgress($this->batchId, false, $this->giftCardCode, null, $e->getMessage());
            }

            // 重新抛出异常以触发重试机制
            throw $e;
        }
    }

    /**
     * 记录失败信息
     */
    protected function recordFailure(Throwable $e, BatchGiftCardService $batchService): void
    {
        // 获取礼品卡信息用于错误记录
        $cardInfo = $this->getCardInfoSafely();

        // 记录到批量服务的错误日志
        if (!empty($this->batchId)) {
            $batchService->recordError($this->batchId, $this->giftCardCode, $e->getMessage(), $cardInfo);
        }
    }

    /**
     * 判断是否为系统错误
     */
    protected function isSystemError(Throwable $e): bool
    {
        $message = $e->getMessage();

        // 优先检查是否为已知的系统错误
        foreach (self::SYSTEM_ERRORS as $systemError) {
            if (stripos($message, $systemError) !== false) {
                return true;
            }
        }

        // 如果不是已知的系统错误，检查是否为业务错误
        return !$this->isBusinessError($e);
    }

    /**
     * 判断是否为业务逻辑错误
     */
    protected function isBusinessError(Throwable $e): bool
    {
        $message = $e->getMessage();

        foreach (self::BUSINESS_ERRORS as $businessError) {
            if (stripos($message, $businessError) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 安全获取礼品卡信息
     * 优化：避免重复查卡，使用基本信息进行错误记录
     */
    private function getCardInfoSafely(): array
    {
        // 不再重复查卡，使用已有的基本信息进行错误记录
        // 这些信息足够用于错误记录和分析
        return [
            'gift_card_code' => $this->giftCardCode,
            'card_type'      => $this->cardType ?? 'unknown',
            'card_form'      => $this->cardForm ?? 'unknown',
            'room_id'        => $this->roomId ?? 'unknown',
            'batch_id'       => $this->batchId ?? '',
            'error_context'  => 'Failed during redemption process'
        ];
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
    ): self
    {
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
    public function failed(Throwable $exception): void
    {
        // 如果是业务逻辑错误，已经在handle方法中处理过了，不需要重复处理
        if ($this->isBusinessError($exception)) {
            $this->getLogger()->info("业务逻辑错误已在handle方法中处理", [
                'job_id'    => $this->job?->getJobId(),
                'card_code' => $this->giftCardCode,
                'error'     => $exception->getMessage()
            ]);
            return;
        }

        $this->getLogger()->error("礼品卡兑换任务最终失败", [
            'job_id'    => $this->job?->getJobId(),
            'card_code' => $this->giftCardCode,
            'room_id'   => $this->roomId,
            'batch_id'  => $this->batchId,
            'error'     => $exception->getMessage(),
            'attempts'  => $this->attempts(),
            'max_tries' => $this->tries,
            'trace'     => $exception->getTraceAsString()
        ]);

        // 发送最终失败消息
        if (!in_array($this->roomId, ['brother-card@api', 'no-send-msg@api'])) {
            send_msg_to_wechat($this->roomId, "兑换失败\n-------------------------\n" . $this->giftCardCode . "\n" . $exception->getMessage());
        }

        // 确保更新批量任务进度
        if (!empty($this->batchId)) {
            try {
                $batchService = app(BatchGiftCardService::class);
                $batchService->updateProgress($this->batchId, false, $this->giftCardCode, null, $exception->getMessage());
            } catch (Throwable $e) {
                $this->getLogger()->error("更新批量任务进度失败", [
                    'batch_id'  => $this->batchId,
                    'card_code' => $this->giftCardCode,
                    'error'     => $e->getMessage()
                ]);
            }
        }
    }
}
