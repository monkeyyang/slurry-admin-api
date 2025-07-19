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
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use App\Models\ItunesTradePlan;

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
        '不符合倍数要求',
        '已兑换成功，请勿重复提交'  // 添加防重复提交的错误类型
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
        'Server Error',
        '登录失败，需要重新验证账号',  // 登录失败类型错误，需要重试
        'redis: nil'  // Redis连接失败
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

            // 兑换成功后处理账号状态
            if ($result['success']) {
                $this->handleSuccessfulExchange($result);
            }

            // 发送微信消息 - 使用try-catch避免影响主流程
            if (!in_array($this->roomId, ['brother-card@api', 'no-send-msg@api'])) {
                try {
                    // 检查是否存在wechat_msg键
                    if (!empty($result['wechat_msg'])) {
                        $sendResult = send_msg_to_wechat($this->roomId, $result['wechat_msg'], 'MT_SEND_TEXTMSG', true, 'redeem-gift-card');
                        if (!$sendResult) {
                            $this->getLogger()->warning("微信消息发送失败", [
                                'card_code'      => $this->giftCardCode,
                                'room_id'        => $this->roomId,
                                'message_length' => strlen($result['wechat_msg'])
                            ]);
                        } else {
                            $this->getLogger()->info("微信消息发送成功（队列）", [
                                'card_code'  => $this->giftCardCode,
                                'room_id'    => $this->roomId,
                                'message_id' => $sendResult
                            ]);
                        }
                    } else {
                        $this->getLogger()->warning("兑换结果中缺少微信消息内容", [
                            'card_code'   => $this->giftCardCode,
                            'result_keys' => array_keys($result)
                        ]);
                    }
                } catch (Throwable $e) {
                    $this->getLogger()->warning("微信消息发送异常，但不影响兑换结果", [
                        'card_code' => $this->giftCardCode,
                        'error'     => $e->getMessage()
                    ]);
                }
            }

            // 更新批量任务进度 - 成功
            if (!empty($this->batchId)) {
                try {
                    $batchService->updateProgress($this->batchId, true, $this->giftCardCode, $result);
                } catch (Throwable $e) {
                    $this->getLogger()->warning("批量任务进度更新失败，但不影响兑换结果", [
                        'batch_id'  => $this->batchId,
                        'card_code' => $this->giftCardCode,
                        'error'     => $e->getMessage()
                    ]);
                }
            }

        } catch (Throwable $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // 记录失败信息
            $this->recordFailure($e, $batchService);

            // 兑换失败后处理账号状态
            $this->handleFailedExchange($e);

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

                // 发送失败消息 - 使用try-catch避免二次失败
                if (!in_array($this->roomId, ['brother-card@api', 'no-send-msg@api'])) {
                    try {
                        $failMessage = "兑换失败\n-------------------------\n" . $this->giftCardCode . "\n" . $e->getMessage();
//                        $failMessage = "❌ 兑换失败\n--------------------------\n" . $this->giftCardCode . "\n";
                        $sendResult = send_msg_to_wechat($this->roomId, $failMessage, 'MT_SEND_TEXTMSG', true, 'redeem-gift-card');
                        if (!$sendResult) {
                            $this->getLogger()->warning("失败消息发送未成功", [
                                'card_code' => $this->giftCardCode,
                                'room_id'   => $this->roomId
                            ]);
                        }
                    } catch (Throwable $msgError) {
                        $this->getLogger()->warning("失败消息发送异常", [
                            'card_code' => $this->giftCardCode,
                            'error'     => $msgError->getMessage()
                        ]);
                    }
                }

                // 更新批量任务进度 - 失败
                if (!empty($this->batchId)) {
                    try {
                        $batchService->updateProgress($this->batchId, false, $this->giftCardCode, null, $e->getMessage());
                    } catch (Throwable $progressError) {
                        $this->getLogger()->warning("批量任务进度更新失败", [
                            'batch_id'  => $this->batchId,
                            'card_code' => $this->giftCardCode,
                            'error'     => $progressError->getMessage()
                        ]);
                    }
                }

                // 不抛出异常，避免重试
                return;
            }

            // 使用统一的重试判断逻辑
            if ($this->shouldRetry($e)) {
                // 需要重试的异常（系统错误、未分类错误）
                $this->getLogger()->error("礼品卡兑换失败，将进行重试", [
                    'job_id'            => $this->job->getJobId(),
                    'card_code'         => $this->giftCardCode,
                    'room_id'           => $this->roomId,
                    'batch_id'          => $this->batchId,
                    'error'             => $e->getMessage(),
                    'error_type'        => $this->getErrorType($e),
                    'execution_time_ms' => $executionTime,
                    'attempt'           => $this->attempts(),
                    'max_tries'         => $this->tries,
                    'trace'             => $e->getTraceAsString()
                ]);

                // 更新批量任务进度 - 失败（但可能会重试）
                if (!empty($this->batchId)) {
                    try {
                        $batchService->updateProgress($this->batchId, false, $this->giftCardCode, null, $e->getMessage());
                    } catch (Throwable $progressError) {
                        $this->getLogger()->warning("批量任务进度更新失败", [
                            'batch_id' => $this->batchId,
                            'error'    => $progressError->getMessage()
                        ]);
                    }
                }

                // 重新抛出异常以触发重试机制
                throw $e;
            } else {
                // 不需要重试的异常（后续处理错误）
                $this->getLogger()->warning("后续处理失败，但不重试（兑换可能已成功）", [
                    'job_id'            => $this->job->getJobId(),
                    'card_code'         => $this->giftCardCode,
                    'batch_id'          => $this->batchId,
                    'error'             => $e->getMessage(),
                    'error_type'        => $this->getErrorType($e),
                    'execution_time_ms' => $executionTime,
                    'attempt'           => $this->attempts(),
                    'reason'            => $this->getNoRetryReason($e)
                ]);

                // 不抛出异常，避免重试
                return;
            }
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
     * 判断是否为业务逻辑错误（不需要重试）
     * 这类错误重试也无法解决，如：卡无效、已兑换、重复提交等
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
     * 判断是否为后续处理异常（不需要重试）
     * 核心业务已成功，只是附加功能失败，如：微信消息发送、批量进度更新等
     */
    protected function isPostProcessingError(Throwable $e): bool
    {
        $message = $e->getMessage();
        $trace   = $e->getTraceAsString();

        // 后续处理异常：微信消息发送、批量任务进度更新等
        $postProcessingErrors = [
            'send_msg_to_wechat',         // 微信消息发送失败
            'updateProgress',             // 批量任务进度更新失败
            'BatchGiftCardService',       // 批量服务相关错误
            'recordError',                // 错误记录失败
            'event(',                     // 事件触发失败
            'Event::dispatch',            // 事件分发失败
            'TradeLogCreated',            // 交易日志事件失败
        ];

        // 检查是否为后续处理异常
        foreach ($postProcessingErrors as $postError) {
            if (stripos($message, $postError) !== false || stripos($trace, $postError) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断是否为系统错误（需要重试）
     * 这类错误可能是临时性的，重试可能会成功，如：网络错误、数据库连接失败等
     */
    protected function isSystemError(Throwable $e): bool
    {
        $message = $e->getMessage();
        $trace   = $e->getTraceAsString();

        // 已知的系统错误模式
        $systemErrors = array_merge(self::SYSTEM_ERRORS, [
            'validateGiftCard',           // 验证礼品卡失败
            'findMatchingRate',           // 查找汇率失败
            'findAvailablePlan',          // 查找计划失败
            'findAvailableAccount',       // 查找账号失败
            'executeRedemption',          // 执行兑换失败
            'GiftCardService::redeem',    // 兑换服务失败
            'Database',                   // 数据库相关错误
            'Connection',                 // 连接相关错误
            'QueryException',             // 查询异常
            'PDOException',               // PDO异常
            'Redis',                      // Redis相关错误
            'API',                        // API调用失败
            'Http',                       // HTTP请求失败
            'Curl',                       // CURL错误
            'Socket',                     // Socket错误
            'login failed',               // 登录失败
            'need login',                 // 需要登录
            'session expired',            // 会话过期
            'authentication failed',     // 认证失败
            'unauthorized',               // 未授权
            'login required'              // 需要登录
        ]);

        // 检查是否为系统错误
        foreach ($systemErrors as $systemError) {
            if (stripos($message, $systemError) !== false || stripos($trace, $systemError) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断异常是否需要重试
     * 统一的重试判断逻辑
     */
    protected function shouldRetry(Throwable $e): bool
    {
        // 1. 业务逻辑错误：不重试（如卡无效、已兑换等）
        if ($this->isBusinessError($e)) {
            return false;
        }

        // 2. 后续处理错误：不重试（核心业务已成功）
        if ($this->isPostProcessingError($e)) {
            return false;
        }

        // 3. 系统错误：重试（如网络问题、数据库连接失败等）
        if ($this->isSystemError($e)) {
            return true;
        }

        // 4. 未明确分类的错误：保守起见，重试
        return true;
    }

    /**
     * 获取错误类型（用于日志记录）
     */
    protected function getErrorType(Throwable $e): string
    {
        if ($this->isBusinessError($e)) {
            return 'business_error';
        } elseif ($this->isPostProcessingError($e)) {
            return 'post_processing_error';
        } elseif ($this->isSystemError($e)) {
            return 'system_error';
        } else {
            return 'unknown_error';
        }
    }

    /**
     * 获取不重试的原因（用于日志记录）
     */
    protected function getNoRetryReason(Throwable $e): string
    {
        if ($this->isBusinessError($e)) {
            return '业务逻辑错误，重试无法解决';
        } elseif ($this->isPostProcessingError($e)) {
            return '核心业务可能已成功，仅后续处理失败';
        } else {
            return '未知原因';
        }
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

        // 尝试更新pending记录状态
        $this->updatePendingRecordOnFailure($exception);

        // 发送最终失败消息
        if (!in_array($this->roomId, ['brother-card@api', 'no-send-msg@api'])) {
            try {
                send_msg_to_wechat($this->roomId, "兑换失败\n-------------------------\n" . $this->giftCardCode . "\n" . $exception->getMessage());
            } catch (Throwable $msgError) {
                $this->getLogger()->warning("失败消息发送失败", [
                    'card_code' => $this->giftCardCode,
                    'error'     => $msgError->getMessage()
                ]);
            }
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

    /**
     * 在任务最终失败时更新pending记录状态
     */
    private function updatePendingRecordOnFailure(Throwable $exception): void
    {
        try {
            // 查找对应的pending记录
            $pendingRecord = ItunesTradeAccountLog::where('code', $this->giftCardCode)
                ->where('status', ItunesTradeAccountLog::STATUS_PENDING)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$pendingRecord) {
                $this->getLogger()->info("未找到对应的pending记录", [
                    'card_code' => $this->giftCardCode,
                    'batch_id'  => $this->batchId
                ]);
                return;
            }

            // 检查记录是否与当前任务相关（通过batch_id或时间判断）
            $isRelatedRecord = false;

            if (!empty($this->batchId) && $pendingRecord->batch_id === $this->batchId) {
                $isRelatedRecord = true;
            } elseif (empty($this->batchId) && empty($pendingRecord->batch_id)) {
                // 非批量任务，检查时间是否接近（5分钟内）
                $timeDiff = abs($pendingRecord->created_at->diffInMinutes(now()));
                if ($timeDiff <= 5) {
                    $isRelatedRecord = true;
                }
            }

            if (!$isRelatedRecord) {
                $this->getLogger()->info("找到的pending记录与当前任务不相关", [
                    'card_code'         => $this->giftCardCode,
                    'record_id'         => $pendingRecord->id,
                    'record_batch_id'   => $pendingRecord->batch_id,
                    'current_batch_id'  => $this->batchId,
                    'record_created_at' => $pendingRecord->created_at
                ]);
                return;
            }

            // 更新pending记录状态
            $errorMessage = "队列任务最终失败: " . $exception->getMessage();
            $pendingRecord->update([
                'status'        => ItunesTradeAccountLog::STATUS_FAILED,
                'error_message' => $errorMessage
            ]);

            $this->getLogger()->info("已更新pending记录状态为失败", [
                'record_id'     => $pendingRecord->id,
                'card_code'     => $this->giftCardCode,
                'error_message' => $errorMessage
            ]);

        } catch (Throwable $e) {
            $this->getLogger()->error("更新pending记录状态失败", [
                'card_code' => $this->giftCardCode,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 处理成功兑换后的账号状态
     */
    private function handleSuccessfulExchange(array $result): void
    {
        try {
            // 从结果中获取账号ID
            $accountId = $result['account_id'] ?? null;
            if (!$accountId) {
                $this->getLogger()->warning("兑换成功但未找到账号ID", [
                    'card_code' => $this->giftCardCode,
                    'result'    => $result
                ]);
                return;
            }

            // 获取账号信息
            $account = ItunesTradeAccount::find($accountId);
            if (!$account) {
                $this->getLogger()->warning("未找到账号信息", [
                    'card_code'  => $this->giftCardCode,
                    'account_id' => $accountId
                ]);
                return;
            }

            // 获取计划信息
            $planId = $result['plan_id'] ?? null;
            if (!$planId) {
                $this->getLogger()->warning("兑换成功但未找到计划ID", [
                    'card_code'  => $this->giftCardCode,
                    'account_id' => $accountId
                ]);
                return;
            }

            $plan = ItunesTradePlan::find($planId);
            if (!$plan) {
                $this->getLogger()->warning("未找到计划信息", [
                    'card_code' => $this->giftCardCode,
                    'plan_id'   => $planId
                ]);
                return;
            }

            // 刷新账号数据，确保获取最新的金额
            $account->refresh();
            $currentDay = $account->current_plan_day ?? 1;

            // 检查总额度是否达成
            $totalAmountReached = ($account->amount >= $plan->total_amount);

            // 检查当日计划是否达成（最后一天不判断）
            $planDays  = $plan->plan_days ?? 1;
            $isLastDay = $currentDay >= $planDays;
            $dailyAmountReached = false; // 默认不达成
            if(!$isLastDay) {
                $dailyAmountReached = $this->checkDailyAmountReached($account, $plan, $currentDay);
            }
            $this->getLogger()->info("检查兑换成功后的账号状态", [
                'card_code'            => $this->giftCardCode,
                'account_id'           => $account->id,
                'account_email'        => $account->account,
                'current_amount'       => $account->amount,
                'total_amount_limit'   => $plan->total_amount,
                'current_day'          => $currentDay,
                'total_amount_reached' => $totalAmountReached,
                'daily_amount_reached' => $dailyAmountReached
            ]);

            // 根据达成情况决定账号状态
            if ($totalAmountReached) {
                // 总额度达成，完成整个计划
                $account->update([
                    'status' => ItunesTradeAccount::STATUS_COMPLETED,
                    // 'current_plan_day' => null,
                    // 'plan_id' => null,
                ]);
                // 请求登出此账号
                ProcessAppleAccountLogoutJob::dispatch($account->id, 'plan_completed_check');

                // 发送微信通知
                try {
                    $result = \send_wechat_template(
                        '45958721463@chatroom',
                        'redeem_plan_completed',
                        [
                            'account' => $account->account,
                            'country' => $account->country_code ?? 'Unknown',
                            'balance' => $account->amount
                        ],
                        'redeem-gift-card-completion'
                    );

                    if ($result) {
                        $this->getLogger()->info("账号完成微信通知发送成功", [
                            'card_code'  => $this->giftCardCode,
                            'account_id' => $account->id,
                            'account'    => $account->account,
                            'room_id'    => $this->roomId,
                            'message_id' => $result
                        ]);
                    } else {
                        $this->getLogger()->error("账号完成微信通知发送失败", [
                            'card_code'  => $this->giftCardCode,
                            'account_id' => $account->id,
                            'account'    => $account->account,
                            'room_id'    => $this->roomId
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->getLogger()->error("账号完成微信通知发送异常", [
                        'card_code'  => $this->giftCardCode,
                        'account_id' => $account->id,
                        'account'    => $account->account,
                        'error'      => $e->getMessage()
                    ]);
                }

                $this->getLogger()->info("总额度达成，账号计划完成", [
                    'card_code'    => $this->giftCardCode,
                    'account_id'   => $account->id,
                    'final_amount' => $account->amount
                ]);
            } elseif ($dailyAmountReached) {
                // 当日计划达成，但总额度未达成
                if ($currentDay >= $plan->plan_days) {
                    // 已是最后一天，完成计划
                    $account->update([
                        'status' => ItunesTradeAccount::STATUS_COMPLETED,
                        // 'current_plan_day' => null,
                        // 'plan_id' => null,
                    ]);

                    $this->getLogger()->info("最后一天计划达成，账号计划完成", [
                        'card_code'    => $this->giftCardCode,
                        'account_id'   => $account->id,
                        'final_amount' => $account->amount
                    ]);
                } else {
                    // 进入下一天等待状态
                    $nextDay = $currentDay + 1;
                    $account->update([
                        'status'           => ItunesTradeAccount::STATUS_WAITING,
                        'current_plan_day' => $nextDay,
                    ]);

                    $this->getLogger()->info("当日计划达成，账号进入下一天等待", [
                        'card_code'  => $this->giftCardCode,
                        'account_id' => $account->id,
                        'next_day'   => $nextDay
                    ]);
                }
            } else {
                // 总额度和当日计划都未达成，改为processing状态继续处理
                $account->update([
                    'status' => ItunesTradeAccount::STATUS_PROCESSING,
                ]);

                $this->getLogger()->info("总额度和当日计划都未达成，账号改为processing状态", [
                    'card_code'          => $this->giftCardCode,
                    'account_id'         => $account->id,
                    'current_amount'     => $account->amount,
                    'total_amount_limit' => $plan->total_amount
                ]);
            }

        } catch (Throwable $e) {
            $this->getLogger()->error("处理成功兑换后的账号状态失败", [
                'card_code' => $this->giftCardCode,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 处理失败兑换后的账号状态
     */
    private function handleFailedExchange(Throwable $e): void
    {
        try {
            // 尝试从日志记录中获取账号信息
            $log = ItunesTradeAccountLog::where('code', $this->giftCardCode)
                ->where('status', ItunesTradeAccountLog::STATUS_PENDING)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$log || !$log->account_id) {
                $this->getLogger()->info("兑换失败但未找到相关账号信息", [
                    'card_code' => $this->giftCardCode,
                    'error'     => $e->getMessage()
                ]);
                return;
            }

            // 获取账号信息
            $account = ItunesTradeAccount::find($log->account_id);
            if (!$account) {
                $this->getLogger()->warning("未找到账号信息", [
                    'card_code'  => $this->giftCardCode,
                    'account_id' => $log->account_id
                ]);
                return;
            }

            // 检查账号当前状态
            if ($account->status !== ItunesTradeAccount::STATUS_LOCKING) {
                $this->getLogger()->info("账号状态不是locking，无需处理", [
                    'card_code'      => $this->giftCardCode,
                    'account_id'     => $account->id,
                    'current_status' => $account->status
                ]);
                return;
            }

            // 将账号状态改为processing，使其可以被重新分配
            $account->update([
                'status' => ItunesTradeAccount::STATUS_PROCESSING,
            ]);

            $this->getLogger()->info("兑换失败，账号状态已改为processing", [
                'card_code'     => $this->giftCardCode,
                'account_id'    => $account->id,
                'account_email' => $account->account,
                'error'         => $e->getMessage()
            ]);

        } catch (Throwable $statusError) {
            $this->getLogger()->error("处理失败兑换后的账号状态失败", [
                'card_code'    => $this->giftCardCode,
                'error'        => $e->getMessage(),
                'status_error' => $statusError->getMessage(),
                'trace'        => $statusError->getTraceAsString()
            ]);
        }
    }

    /**
     * 检查当日计划是否达成
     */
    private function checkDailyAmountReached(ItunesTradeAccount $account, ItunesTradePlan $plan, int $currentDay): bool
    {
        try {
            // 获取当天已成功兑换的总额
            $dailySpent = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $currentDay)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');

            // 获取当天的计划额度
            $dailyAmounts   = $plan->daily_amounts ?? [];
            $dailyLimit     = $dailyAmounts[$currentDay - 1] ?? 0;
            $dailyTarget    = $dailyLimit + $plan->float_amount;
            $dailyMinTarget = $dailyLimit - $plan->float_amount;
//            if($currentDay == 2) { // 临时要求，当计划为第二天时要求最小额度增加50，例如500->550
//                $dailyMinTarget += 50;
//            }

            // 检查是否达到当天的目标额度(达到最小当日目标)
            $reached = ($dailySpent >= $dailyMinTarget);

            $this->getLogger()->info("检查当日计划达成情况", [
                'account_id'       => $account->id,
                'day'              => $currentDay,
                'daily_spent'      => $dailySpent,
                'daily_limit'      => $dailyLimit,
                'float_amount'     => $plan->float_amount,
                'daily_target'     => $dailyTarget,
                'min_daily_target' => $dailyMinTarget,
                'reached'          => $reached
            ]);

            return $reached;

        } catch (Throwable $e) {
            $this->getLogger()->error("检查当日计划达成情况失败", [
                'account_id' => $account->id,
                'day'        => $currentDay,
                'error'      => $e->getMessage()
            ]);
            return false;
        }
    }
}
