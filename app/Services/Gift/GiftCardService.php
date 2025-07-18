<?php

namespace App\Services\Gift;

use App\Events\TradeLogCreated;
use App\Exceptions\GiftCardExchangeException;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeRate;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccountLog;
use App\Models\MrRoom;
use App\Models\MrRoomBill;
use App\Models\MrRoomGroup;
use App\Services\GiftCardApiClient;
use App\Services\GiftCardExchangeService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class GiftCardService
{
    protected GiftCardExchangeService $exchangeService;
    protected FindAccountService $findAccountService;

    // 兑换任务的属性
    protected string $giftCardCode     = '';
    protected string $roomId           = '';
    protected string $cardType         = '';
    protected string $cardForm         = '';
    protected string $batchId          = '';
    protected string $msgId            = '';
    protected string $wxId             = '';
    protected string $countryCode      = '';
    protected array  $additionalParams = [];

    // 业务逻辑错误列表 - 这些错误不需要记录堆栈跟踪
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

    public function __construct(
        GiftCardExchangeService $exchangeService,
        FindAccountService $findAccountService
    ) {
        $this->exchangeService = $exchangeService;
        $this->findAccountService = $findAccountService;
    }

    /**
     * 获取礼品卡兑换专用日志实例
     */
    protected function getLogger(): LoggerInterface
    {
        return Log::channel('gift_card_exchange');
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
     * 重置所有属性
     */
    public function reset(): self
    {
        $this->giftCardCode     = '';
        $this->roomId           = '';
        $this->cardType         = '';
        $this->cardForm         = '';
        $this->batchId          = '';
        $this->msgId            = '';
        $this->wxId             = '';
        $this->countryCode      = '';
        $this->additionalParams = [];
        return $this;
    }

    /**
     * 验证必要参数
     */
    protected function validateParams(): void
    {
        if (empty($this->giftCardCode)) {
            throw new \InvalidArgumentException('礼品卡码不能为空');
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
     * 兑换礼品卡（新的属性设置方式）
     * @throws Exception
     */
    public function redeemGiftCard(): array
    {
        $this->validateParams();

        $log = null;
        try {
            // 1. 创建初始日志记录（状态为pending）
            $log = $this->createInitialLog();

            // 2. 验证礼品卡
            $giftCardInfo = $this->validateGiftCard($this->giftCardCode);

            // 3. 更新日志的礼品卡信息
            $log->update([
                'amount'       => $giftCardInfo['amount'],
                'country_code' => $giftCardInfo['country_code']
            ]);

            // 4. 查找匹配的汇率
            $rate = $this->findMatchingRate($giftCardInfo, $this->roomId, $this->cardType, $this->cardForm);

            // 5. 查找可用的计划
            $plan = $this->findAvailablePlan($rate->id);

            // 6. 查找可用的账号
            $account = $this->findAvailableAccount($plan, $this->roomId, $giftCardInfo);

            // 7. 更新日志的账号和计划信息
            $log->update([
                'account_id' => $account->id,
                'plan_id'    => $plan->id,
                'rate_id'    => $rate->id,
                'day'        => $account->current_plan_day ?? 1
            ]);

            // 8. 执行兑换
            $result = $this->executeRedemption(
                $this->giftCardCode,
                $giftCardInfo,
                $rate,
                $plan,
                $account,
                $this->batchId,
                $log
            );

            return $result;

        } catch (Exception $e) {
            // 确保所有异常都能正确更新pending记录状态
            $this->handleRedemptionException($e, $log);
            throw $e;
        }
    }

    /**
     * 判断账号当日计划是否完成
     */
    protected function isDailyPlanCompleted(ItunesTradeAccount $account, int $currentDay): bool
    {
        $plan = $account->plan;
        if (!$plan) {
            return false;
        }
        $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;
        return $dailyAmount >= $dailyLimit;
    }

    /**
     * 处理兑换异常，确保pending记录状态得到正确更新
     */
    private function handleRedemptionException(Exception $e, ?ItunesTradeAccountLog $log): void
    {
        $errorMessage = $e->getMessage();
        $errorContext = [
            'gift_card_code' => $this->giftCardCode,
            'room_id'        => $this->roomId,
            'batch_id'       => $this->batchId,
            'error'          => $errorMessage,
            'error_type'     => get_class($e),
            'log_id'         => $log?->id
        ];

        // 如果没有创建日志记录，说明在初始阶段就失败了
        if (!$log) {
            $this->getLogger()->error("兑换在初始阶段失败，未创建日志记录", $errorContext);
            return;
        }

        // 检查当前日志状态
        $log->refresh(); // 刷新数据，确保获取最新状态
        if ($log->status !== ItunesTradeAccountLog::STATUS_PENDING) {
            $this->getLogger()->info("日志状态已不是pending，无需更新", array_merge($errorContext, [
                'current_status' => $log->status
            ]));
            return;
        }

        // 尝试更新pending记录状态为失败
        try {
            $log->update([
                'status'        => ItunesTradeAccountLog::STATUS_FAILED,
                'error_message' => $errorMessage
            ]);

            // 触发日志更新事件
            event(new TradeLogCreated($log->fresh()));

            $this->getLogger()->info("已更新pending记录状态为失败", array_merge($errorContext, [
                'updated_status' => ItunesTradeAccountLog::STATUS_FAILED
            ]));

        } catch (Exception $updateException) {
            // 如果更新日志状态也失败了，记录这个严重错误
            $this->getLogger()->critical("更新pending记录状态失败", array_merge($errorContext, [
                'update_error' => $updateException->getMessage(),
                'update_trace' => $updateException->getTraceAsString()
            ]));
        }
    }

    /**
     * 兑换礼品卡（兼容旧版本，已废弃）
     * @throws Exception
     * @deprecated 使用属性设置方法替代
     */
    public function redeem(string $code, string $roomId, string $cardType, string $cardForm, string $batchId, string $msgid = ''): array
    {
        return $this->setGiftCardCode($code)
            ->setRoomId($roomId)
            ->setCardType($cardType)
            ->setCardForm($cardForm)
            ->setBatchId($batchId)
            ->setMsgId($msgid)
            ->redeemGiftCard();
    }

    /**
     * 验证礼品卡
     * @throws Exception
     */
    protected function validateGiftCard(string $code): array
    {
        // 调用GiftCardExchangeService的validateGiftCard方法
        $result = $this->exchangeService->validateGiftCard($code);
        $this->getLogger()->info('查卡返回数据：', $result);
        if (!$result['is_valid'] || $result['balance'] <= 0) {
            throw new Exception("礼品卡无效: " . ($result['message'] ?? '未知错误'));
        }
        $this->countryCode = $result['country_code'];
        return [
            'country_code' => $result['country_code'],
            'amount'       => $result['balance'],
            'currency'     => $result['currency'] ?? 'USD',
            'valid'        => true
        ];
    }

    /**
     * 查找匹配的汇率
     * @throws Exception
     */
    protected function findMatchingRate(array $giftCardInfo, string $roomId, string $cardType, string $cardForm): ItunesTradeRate
    {
        $countryCode = $giftCardInfo['country_code'];
        $amount      = $giftCardInfo['amount'];

        // 基础查询条件
        $query = ItunesTradeRate::where('country_code', $countryCode)
            ->where('card_type', $cardType)
            ->where('card_form', $cardForm)
            ->where('status', ItunesTradeRate::STATUS_ACTIVE);

        // 优先级1: 匹配room_id
        $rateWithRoomId = (clone $query)->where('room_id', $roomId)->get();
        if ($rateWithRoomId->isNotEmpty()) {
            $rate = $this->selectBestRateByAmount($rateWithRoomId, $amount);
            if ($rate) {
                $this->getLogger()->info("找到匹配room_id的汇率", ['rate_id' => $rate->id, 'room_id' => $roomId]);
                return $rate;
            }
        }

        // 优先级2: 匹配群组（通过room_id获取群组）
        $groupId = $this->getGroupIdByRoomId($roomId);
        $this->getLogger()->info("找到群组ID", ['group_id' => $groupId]);
        if ($groupId) {
            $rateWithGroup = (clone $query)->where('group_id', $groupId)->get();
            if ($rateWithGroup->isNotEmpty()) {
                $rate = $this->selectBestRateByAmount($rateWithGroup, $amount);
                if ($rate) {
                    $this->getLogger()->info("找到匹配群组的汇率", ['rate_id' => $rate->id, 'group_id' => $groupId]);
                    return $rate;
                }
            }
        }

        // 优先级3: 空room_id和空群组的汇率
        $defaultRates = (clone $query)
            ->whereNull('room_id')
            ->whereNull('group_id')
            ->get();

        if ($defaultRates->isNotEmpty()) {
            $this->getLogger()->info("第一次筛选的汇率", $defaultRates->toArray());
            $rate = $this->selectBestRateByAmount($defaultRates, $amount);
            if ($rate) {
                $this->getLogger()->info("找到默认汇率", ['rate_id' => $rate->id]);
                return $rate;
            }
        }

        throw new Exception("未找到符合条件的汇率: 国家={$countryCode}, 卡类型={$cardType}, 卡形式={$cardForm}, 面额={$amount}");
    }

    /**
     * 根据面额选择最佳汇率
     */
    protected function selectBestRateByAmount($rates, float $amount): ?ItunesTradeRate
    {
        foreach ($rates as $rate) {
            // 1. 检查固定面额
            if ($rate->amount_constraint === ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED) {
                $fixedAmounts = $rate->fixed_amounts ?? [];

                // 如果是字符串，尝试JSON解码
                if (is_string($fixedAmounts)) {
                    $decodedAmounts = json_decode($fixedAmounts, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAmounts)) {
                        $fixedAmounts = $decodedAmounts;
                    }
                }

                $this->getLogger()->info("固定面额检查", [
                    'original_fixed_amounts' => $rate->fixed_amounts,
                    'parsed_fixed_amounts'   => $fixedAmounts,
                    'amount'                 => $amount,
                    'amount_type'            => gettype($amount),
                    'type'                   => gettype($fixedAmounts)
                ]);

                // 检查是否匹配固定面额
                $isMatched = false;
                if (is_array($fixedAmounts)) {
                    // 精确匹配
                    if (in_array($amount, $fixedAmounts)) {
                        $isMatched = true;
                    } else {
                        // 类型宽松匹配（处理整数和浮点数的差异）
                        foreach ($fixedAmounts as $fixedAmount) {
                            if (abs($amount - (float)$fixedAmount) < 0.01) {
                                $isMatched = true;
                                $this->getLogger()->info("通过宽松匹配找到固定面额", [
                                    'amount'        => $amount,
                                    'matched_value' => $fixedAmount,
                                    'diff'          => abs($amount - (float)$fixedAmount)
                                ]);
                                break;
                            }
                        }
                    }
                }

                if ($isMatched) {
                    $this->getLogger()->info("匹配到固定面额汇率", ['rate_id' => $rate->id]);
                    return $rate;
                }
            } // 2. 检查倍数要求
            elseif ($rate->amount_constraint === ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE) {
                $multipleBase = $rate->multiple_base ?? 1;
                $minAmount    = $rate->min_amount ?? 0;
                $maxAmount    = ($rate->max_amount > 0) ? $rate->max_amount : PHP_FLOAT_MAX;

                $this->getLogger()->info("倍数要求检查", [
                    'amount'        => $amount,
                    'multiple_base' => $multipleBase,
                    'min_amount'    => $minAmount,
                    'max_amount'    => $maxAmount,
                    'modulo_result' => $amount % $multipleBase
                ]);

                if ($amount % $multipleBase == 0 &&
                    $amount >= $minAmount &&
                    $amount <= $maxAmount) {
                    $this->getLogger()->info("匹配到倍数要求汇率", ['rate_id' => $rate->id]);
                    return $rate;
                }
            } // 3. 检查全面额
            elseif ($rate->amount_constraint === ItunesTradeRate::AMOUNT_CONSTRAINT_ALL) {
                $minAmount = $rate->min_amount ?? 0;
                $maxAmount = ($rate->max_amount > 0) ? $rate->max_amount : PHP_FLOAT_MAX;

                $this->getLogger()->info("全面额检查", [
                    'amount'     => $amount,
                    'min_amount' => $minAmount,
                    'max_amount' => $maxAmount
                ]);

                if ($amount >= $minAmount && $amount <= $maxAmount) {
                    $this->getLogger()->info("匹配到全面额汇率", ['rate_id' => $rate->id]);
                    return $rate;
                }
            }
        }

        return null;
    }

    /**
     * 通过room_id获取群组ID
     */
    protected function getGroupIdByRoomId(string $roomId): ?int
    {
        // 先根据roomId获取room表id
        $roomInfo = MrRoom::getByRoomId($roomId);
        if ($roomInfo) $roomId = $roomInfo->id;
        return MrRoomGroup::whereRaw("FIND_IN_SET(?, room_ids)", [$roomId])
            ->orderByDesc('id')
            ->value('id');
    }

    /**
     * 查找可用的计划
     */
    protected function findAvailablePlan(int $rateId): ItunesTradePlan
    {
        $plan = ItunesTradePlan::where('rate_id', $rateId)
            ->where('status', ItunesTradePlan::STATUS_ENABLED)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$plan) {
            throw new Exception("未找到可用的兑换计划: rate_id={$rateId}");
        }

        $this->getLogger()->info("找到可用计划", ['plan_id' => $plan->id, 'rate_id' => $rateId]);

        return $plan;
    }

    /**
     * 查找可用账号（使用新的FindAccountService）
     * @throws Exception
     *
     * 2024-12-19: 重构使用FindAccountService进行高性能交集筛选
     * 原因：使用新的6层交集筛选机制，提升查找效率和准确性
     */
    protected function findAvailableAccount(
        ItunesTradePlan $plan,
        string          $roomId,
        array           $giftCardInfo,
        int             $lastCheckedId = 0
    ): ItunesTradeAccount
    {
        $this->getLogger()->info("开始使用FindAccountService查找可用账号", [
            'plan_id'           => $plan->id,
            'room_id'           => $roomId,
            'gift_card_amount'  => $giftCardInfo['amount'],
            'gift_card_country' => $giftCardInfo['country_code'],
            'bind_room'         => $plan->bind_room,
            'service_version'   => 'FindAccountService_v2024'
        ]);

        try {
            // 使用新的FindAccountService进行高性能查找
            $giftCardInfo['room_id'] = $roomId; // 确保room_id包含在礼品卡信息中
            $account = $this->findAccountService->findOptimalAccount(
                $plan,
                $roomId,
                $giftCardInfo,
                1, // 当前天数，默认为第1天
                false // 非测试模式，执行真正的锁定
            );

            if ($account) {
                $this->getLogger()->info("FindAccountService成功找到并锁定可用账号", [
                    'account_id'    => $account->id,
                    'account_email' => $account->account,
                    'plan_id'       => $plan->id,
                    'room_id'       => $roomId,
                    'service'       => 'FindAccountService'
                ]);
                return $account;
            }

            // 如果没有找到账号，抛出异常
            throw new Exception("未找到可用的兑换账号");

        } catch (Exception $e) {
            $this->getLogger()->error("FindAccountService查找账号失败", [
                'plan_id'           => $plan->id,
                'room_id'           => $roomId,
                'gift_card_amount'  => $giftCardInfo['amount'],
                'gift_card_country' => $giftCardInfo['country_code'],
                'error'             => $e->getMessage(),
                'service'           => 'FindAccountService'
            ]);

            // 重新抛出异常，保持原有的错误处理流程
            throw $e;
        }
    }

    /**
     * 创建初始日志记录（在验证参数后立即创建）
     */
    protected function createInitialLog(): ItunesTradeAccountLog
    {
        // 检查是否已存在该礼品卡的日志记录
        $existingLog = ItunesTradeAccountLog::where('code', $this->giftCardCode)
            ->whereIn('status', [ItunesTradeAccountLog::STATUS_PENDING, ItunesTradeAccountLog::STATUS_SUCCESS])
            ->orderBy('created_at', 'desc')
            ->first();

        if ($existingLog) {
            $this->getLogger()->warning("礼品卡已存在处理记录", [
                'code'            => $this->giftCardCode,
                'existing_log_id' => $existingLog->id,
                'existing_status' => $existingLog->status,
                'created_at'      => $existingLog->created_at->toDateTimeString()
            ]);

            // 如果已存在成功记录，抛出异常
            if ($existingLog->status === ItunesTradeAccountLog::STATUS_SUCCESS) {
                throw new GiftCardExchangeException(
                    GiftCardExchangeException::CODE_CARD_ALREADY_REDEEMED,
                    "礼品卡 {$this->giftCardCode} 已兑换成功，请勿重复提交"
                );
            }

            // 如果是待处理状态，需要判断是否为超时的记录或同批次重试
            if ($existingLog->status === ItunesTradeAccountLog::STATUS_PENDING) {
                $timeoutMinutes = 5; // 5分钟超时
                $isTimeout = $existingLog->created_at->addMinutes($timeoutMinutes)->isPast();
                $isSameBatch = !empty($existingLog->batch_id) && $existingLog->batch_id === $this->batchId;

                if ($isTimeout || $isSameBatch) {
                    // 超时的pending记录或同批次重试，标记为失败并允许重新处理
                    $reason = $isSameBatch ? '同批次任务重试' : '处理超时';
                    $existingLog->update([
                        'status' => ItunesTradeAccountLog::STATUS_FAILED,
//                        'error_message' => $reason . '，创建新的处理记录'
                    ]);

                    $this->getLogger()->info("检测到可重试的pending记录，标记为失败并允许重试", [
                        'code'                => $this->giftCardCode,
                        'existing_log_id'     => $existingLog->id,
                        'existing_batch_id'   => $existingLog->batch_id,
                        'current_batch_id'    => $this->batchId,
                        'is_timeout'          => $isTimeout,
                        'is_same_batch'       => $isSameBatch,
                        'timeout_minutes'     => $timeoutMinutes,
                        'original_created_at' => $existingLog->created_at->toDateTimeString(),
                        'reason'              => $reason
                    ]);

                    // 继续执行，创建新的日志记录
                } else {
                    // 未超时且不同批次的pending记录，真正的重复提交
                    $remainingTime = now()->diffInMinutes($existingLog->created_at->addMinutes($timeoutMinutes));
                    throw new GiftCardExchangeException(
                        GiftCardExchangeException::CODE_OTHER_ERROR,
                        "礼品卡 {$this->giftCardCode} 正在处理中，请等待 {$remainingTime} 分钟后重试"
                    );
                }
            }
        }

        // 创建新的日志记录（所有可选字段先为空，随着流程推进逐步填充）
        $log = ItunesTradeAccountLog::create([
            'account_id'    => null, // 找到账号后更新
            'room_id'       => $this->roomId,
            'wxid'          => $this->wxId,
            'msgid'         => $this->msgId,
            'batch_id'      => $this->batchId, // 批次ID用于重试判断
            'plan_id'       => null, // 找到计划后更新
            'rate_id'       => null, // 找到汇率后更新
            'code'          => $this->giftCardCode,
            'day'           => 1, // 默认第一天，找到账号后更新
            'amount'        => 0, // 验证礼品卡后更新
            'status'        => ItunesTradeAccountLog::STATUS_PENDING,
            'country_code'  => '', // 验证礼品卡后更新
            'exchange_time' => now(),
        ]);

        // 触发日志创建事件
        event(new TradeLogCreated($log));

        $this->getLogger()->info("创建初始兑换日志记录", [
            'log_id' => $log->id,
            'code'   => $this->giftCardCode
        ]);

        return $log;
    }

    /**
     * 执行兑换
     */
    protected function executeRedemption(
        string             $code,
        array              $giftCardInfo,
        ItunesTradeRate    $rate,
        ItunesTradePlan    $plan,
        ItunesTradeAccount $account,
        string             $batchId,
        ItunesTradeAccountLog $log
    ): array
    {
        return DB::transaction(function () use ($code, $giftCardInfo, $rate, $plan, $account, $batchId, $log) {
            $this->getLogger()->info("开始执行兑换", [
                'code'           => $code,
                'account_id'     => $account->account,
                'plan_id'        => $plan->id,
                'rate_id'        => $rate->id,
                'account_status' => $account->status,
                'log_id'         => $log->id
            ]);

            // 账号已经在findAvailableAccount阶段被锁定为STATUS_LOCKING
            // 这里只需要记录锁定前的状态，用于失败时恢复
            // 通过completed_days字段来推断原始状态
            // $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
            // $currentDay    = $account->current_plan_day ?? 1;

            // // 推断原始状态：如果是第一天且没有完成记录，则为WAITING；否则为PROCESSING
            // $originalStatus = (empty($completedDays) && $currentDay == 1)
            //     ? ItunesTradeAccount::STATUS_WAITING
            //     : ItunesTradeAccount::STATUS_PROCESSING;

            $this->getLogger()->info("账号已在查找阶段锁定", [
                'account_id'               => $account->id,
                'code'                     => $code,
                'current_status'           => $account->status,
//                'inferred_original_status' => $originalStatus
            ]);

            // 日志的账号信息已在findAvailableAccount后更新，这里不需要重复更新

            try {
                // 调用兑换API
                $exchangeData = $this->callExchangeApi($account, $plan, $code);

                // 解析兑换结果
                $result = $this->parseExchangeResult($exchangeData, $account, $rate, $code);

                if ($result['success']) {
                    // 兑换成功，更新日志状态
                    $log->update([
                        'status'           => ItunesTradeAccountLog::STATUS_SUCCESS,
                        'after_amount'     => $result['data']['total_amount'] ?? 0,
                        'error_message'    => null // 清除可能的错误信息
                    ]);

                    // 更新账号余额
                    if (isset($result['data']['total_amount'])) {
                        $totalAmount = $this->parseBalance((string)$result['data']['total_amount']);
                        $account->update(['amount' => $totalAmount]);

                        $this->getLogger()->info("更新账号余额", [
                            'account_id' => $account->account,
                            'new_amount' => $totalAmount,
                            'fund_added' => $result['data']['amount'] ?? 0
                        ]);
                    }

                    // 兑换成功，保持锁定状态不变，让计划任务来处理状态转换
                    $this->getLogger()->info("兑换成功，保持锁定状态", [
                        'code'             => $code,
                        'account_id'       => $account->account,
                        'status_kept'      => 'LOCKING (待计划任务处理)',
                        'original_amount'  => $giftCardInfo['amount'],
                        'exchanged_amount' => $result['data']['amount'] ?? 0
                    ]);

                    // 触发日志更新事件
                    event(new TradeLogCreated($log->fresh()));

                    // 注意：不调用 checkAndUpdateDayCompletion，让计划任务统一处理
                    // $this->checkAndUpdateDayCompletion($account, $plan);

                    // 执行加账处理
                    $buildWechatMsg = $this->processAccountBilling($giftCardInfo['amount'], $rate, $account);

                    // 返回成功结果
                    return [
                        'success'          => true,
                        'log_id'           => $log->id,
                        'account_id'       => $account->id,
                        'account_username' => $account->account,
                        'plan_id'          => $plan->id,
                        'rate_id'          => $rate->id,
                        'country_code'     => $giftCardInfo['country_code'],
                        'original_amount'  => $giftCardInfo['amount'],
                        'exchanged_amount' => $result['data']['amount'] ?? 0,
                        'rate'             => $rate->rate,
                        'total_amount'     => $result['data']['total_amount'] ?? 0,
                        'currency'         => $giftCardInfo['currency'] ?? 'USD',
                        'exchange_time'    => $log->exchange_time ? $log->exchange_time->toISOString() : null,
                        'message'          => $result['message'],
                        'details'          => $result['data']['details'] ?? null,
                        'wechat_msg'       => $buildWechatMsg
                    ];
                } else {
                    // 兑换失败，更新日志状态
                    $errorMessage = $result['message'] ?? '兑换失败';
                    $log->update([
                        'status'        => ItunesTradeAccountLog::STATUS_FAILED,
                        'error_message' => $errorMessage
                    ]);

                    $this->getLogger()->error("兑换失败", [
                        'code'       => $code,
                        'account_id' => $account->account,
                        'log_id'     => $log->id,
                        'error'      => $errorMessage
                    ]);

                    // 兑换失败，恢复账号到锁定前的状态
                    // 注释掉账号状态恢复，让计划任务统一处理
                    // $account->update(['status' => $originalStatus]);

                    // 触发日志更新事件
                    event(new TradeLogCreated($log->fresh()));

                    // 构建失败的微信消息
                    $failureWechatMsg = "兑换失败\n-------------------------\n" . $code . "\n" . $errorMessage;

                    return [
                        'success' => false,
                        'log_id'  => $log->id,
                        'message' => $errorMessage,
                        'data'    => $result['data'] ?? [],
                        'wechat_msg' => $failureWechatMsg
                    ];
                }

            } catch (Exception $e) {
                // 捕获所有异常，确保pending记录状态得到更新
                $errorMessage = $e->getMessage();
                $errorDetails = [
                    'code'       => $code,
                    'account_id' => $account->account,
                    'log_id'     => $log->id,
                    'error'      => $errorMessage,
                    'trace'      => $e->getTraceAsString(),
                    'error_type' => get_class($e)
                ];

                $this->getLogger()->error("兑换过程发生异常", $errorDetails);

                // 更新日志状态为失败，记录详细错误信息
                try {
                    $log->update([
                        'status'        => ItunesTradeAccountLog::STATUS_FAILED,
                        'error_message' => $errorMessage
                    ]);

                    // 触发日志更新事件
                    event(new TradeLogCreated($log->fresh()));

                    $this->getLogger()->info("已更新pending记录状态为失败", [
                        'log_id' => $log->id,
                        'code'   => $code
                    ]);

                } catch (Exception $updateException) {
                    // 如果更新日志状态也失败了，记录这个严重错误
                    $this->getLogger()->critical("更新pending记录状态失败", [
                        'log_id'         => $log->id,
                        'code'           => $code,
                        'original_error' => $errorMessage,
                        'update_error'   => $updateException->getMessage()
                    ]);
                }

                // 重新抛出异常，让上层处理
                throw $e;
            }
        });
    }

    /**
     * 处理加账操作
     *
     * @param float $amount
     * @param ItunesTradeRate $rateObj
     * @param ItunesTradeAccount $account
     * @return string
     */
    private function processAccountBilling(float $amount, ItunesTradeRate $rateObj, ItunesTradeAccount $account): string
    {
        try {
            if (empty($this->roomId)) {
                Log::channel('gift_card_exchange')->error('room_id为空，无法进行加账处理');
                return '';
            }

            // 获取群组信息
            $room = MrRoom::where('room_id', $this->roomId)->first();
            if (!$room) {
                Log::channel('gift_card_exchange')->error("群组 {$this->roomId} 不存在");
                return '';
            }

            $countryCode = $rateObj->country_code;

            // 使用BC函数进行精度计算，保留两位小数
            $amount = bcadd($amount, '0', 2);        // 确保金额为两位小数
            $rate   = bcadd($rateObj->rate, '0', 2); // 确保汇率为两位小数

            // 计算变动金额（原始金额 * 汇率）
            $changeAmount = bcmul($amount, $rate, 2);

            // 获取变动前账单金额
            $beforeMoney = bcadd($room->unsettled ?? 0, '0', 2);

            // 计算变动后账单金额
            $afterMoney = bcadd($beforeMoney, $changeAmount, 2);

            // 开始数据库事务
            DB::beginTransaction();
            try {
                // 写入账单记录到 mr_room_bill 表
                MrRoomBill::create([
                    'room_id'      => $this->roomId,
                    'room_name'    => $room->room_name ?? '未知群组',
                    'event'        => 1, // 兑换事件
                    'msgid'        => $this->msgId,
                    'money'        => $amount,
                    'rate'         => $rate,
                    'fee'          => 0.00,
                    'amount'       => $changeAmount,
                    'card_type'    => $countryCode, // 国家
                    'before_money' => $beforeMoney,
                    'bill_money'   => $afterMoney, // 修正：这应该是变动后的总金额
                    'remark'       => 'iTunes',
                    'op_id'        => '',
                    'op_name'      => '',
                    'code'         => $this->giftCardCode,
                    'content'      => json_encode([
                        'account'          => $account->account,
                        'original_amount'  => $amount,
                        'exchange_rate'    => $rate,
                        'converted_amount' => $changeAmount
                    ]),
                    'note'         => "礼品卡兑换 - {$this->giftCardCode}",
                    'status'       => 0,
                    'is_settle'    => 0,
                    'is_del'       => 0
                ]);

                // 更新群组未结算金额和变更时间
                $room->update([
                    'unsettled'  => $afterMoney,
                    'changed_at' => now()
                ]);

                // 提交事务
                DB::commit();

                // 构建成功消息
                $successMessage = $this->buildSuccessMessage([
                    'card_number'   => $this->giftCardCode,
                    'amount'        => $amount,
                    'rate'          => $rate,
                    'before_money'  => $beforeMoney,
                    'change_amount' => $changeAmount,
                    'after_money'   => $afterMoney,
                    'exchange_time' => now()->format('Y-n-j H:i:s')
                ]);


                Log::channel('gift_card_exchange')->info("群组 {$this->roomId} 加账处理完成", [
                    'msgid'         => $this->msgId,
                    'card_number'   => $this->giftCardCode,
                    'amount'        => $amount,
                    'rate'          => $rate,
                    'change_amount' => $changeAmount,
                    'before_money'  => $beforeMoney,
                    'after_money'   => $afterMoney,
                    'room_id'       => $this->roomId
                ]);

                // 单独记录成功消息，避免日志截断
                Log::channel('gift_card_exchange')->info("微信成功消息内容", [
                    'card_number' => $this->giftCardCode,
                    'success_message' => $successMessage,
                    'message_length' => strlen($successMessage)
                ]);


            } catch (\Exception $e) {
                // 回滚事务
                DB::rollBack();
                throw $e;
            }
            return $successMessage;
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error("加账处理失败: " . $e->getMessage(), [
                'room_id' => $this->roomId,
                'card_number' => $this->giftCardCode,
                'error_trace' => $e->getTraceAsString()
            ]);
        }

        return '';
    }

    /**
     * 构建成功消息
     *
     * @param array $data
     * @return string
     */
    private function buildSuccessMessage(array $data): string
    {
        // 确保所有金额都使用BC函数格式化为两位小数
        $amount       = bcadd($data['amount'], '0', 2);
        $beforeMoney  = bcadd($data['before_money'], '0', 2);
        $rate         = bcadd($data['rate'], '0', 2);          // 汇率保留一位小数
        $changeAmount = bcadd($data['change_amount'], '0', 2); // 变动金额保留整数
        $afterMoney   = bcadd($data['after_money'], '0', 2);

        // 构建消息，确保每个部分都正确
        $message = "[强]兑换成功\n";
        $message .= "---------------------------------\n";
        $message .= "加载卡号：" . $data['card_number'] . "\n";
        $message .= "加载结果：$" . $amount . "（" . $this->countryCode . "）\n";
        $message .= "原始账单：" . $beforeMoney . "\n";
        $message .= "变动金额：" . $amount . "*" . $rate . "=" . $changeAmount . "\n";
        $message .= "当前账单：" . $afterMoney . "\n";
        $message .= "加卡时间：" . $data['exchange_time'];

        // 记录调试信息
        Log::channel('gift_card_exchange')->debug("构建成功消息详情", [
            'card_number' => $data['card_number'],
            'amount' => $amount,
            'country_code' => $this->countryCode,
            'before_money' => $beforeMoney,
            'rate' => $rate,
            'change_amount' => $changeAmount,
            'after_money' => $afterMoney,
            'exchange_time' => $data['exchange_time'],
            'message_preview' => substr($message, 0, 100) . '...',
            'message_length' => strlen($message)
        ]);

        return $message;
    }

    /**
     * 解析兑换结果
     */
    private function parseExchangeResult(array $exchangeResult, ItunesTradeAccount $account, ItunesTradeRate $rate, string $code): array
    {
        // 检查基本数据结构
        if (!isset($exchangeResult['data']['items']) || !is_array($exchangeResult['data']['items'])) {
            $errorMessage = '兑换结果数据结构错误';
            $failureWechatMsg = "兑换失败\n-------------------------\n" . $code . "\n" . $errorMessage;
            return [
                'success' => false,
                'message' => $errorMessage,
                'data'    => [],
                'wechat_msg' => $failureWechatMsg
            ];
        }

        // 查找匹配的兑换项
        $dataId      = $account->account . ":" . $code;
        $matchedItem = null;

        foreach ($exchangeResult['data']['items'] as $item) {
            if (isset($item['data_id']) && $item['data_id'] === $dataId) {
                $matchedItem = $item;
                break;
            }
        }

        if (!$matchedItem) {
            $errorMessage = '未找到匹配的兑换项目';
            $failureWechatMsg = "兑换失败\n-------------------------\n" . $code . "\n" . $errorMessage;
            return [
                'success' => false,
                'message' => $errorMessage,
                'data'    => [],
                'wechat_msg' => $failureWechatMsg
            ];
        }

        $this->getLogger()->info('兑换任务item详情：', $matchedItem);

        // 检查兑换结果
        $result     = $matchedItem['result'] ?? [];
        $resultCode = $result['code'] ?? -1;

        if ($resultCode === 0) {
            // 兑换成功
            $amount      = $this->parseBalance($result['fund'] ?? '0');
            $totalAmount = $this->parseBalance($result['total'] ?? '0');

            return [
                'success' => true,
                'message' => sprintf(
                    "%s:%s兑换成功\n汇率：%s\n%s",
                    $account->account,
                    $code,
                    $rate->rate,
                    $matchedItem['msg'] ?? ''
                ),
                'data'    => [
                    'account'      => $account->account,
                    'amount'       => $amount,
                    'rate'         => $rate->rate,
                    'total_amount' => $totalAmount,
                    'status'       => 'success',
                    'msg'          => $matchedItem['msg'] ?? '',
                    'details'      => json_encode([
                        'card_number'  => $code,
                        'card_type'    => $this->cardType,
                        'country_code' => $this->countryCode,
                        'api_response' => $result
                    ])
                ]
            ];
        } else {
            // 兑换失败
            $errorMessage = sprintf(
                "%s:%s兑换失败\n原因：%s",
                $account->account,
                $code,
                $matchedItem['msg'] ?? '未知原因'
            );
            $failureWechatMsg = "兑换失败\n-------------------------\n" . $code . "\n" . ($matchedItem['msg'] ?? '未知原因');

            return [
                'success' => false,
                'message' => $errorMessage,
                'data'    => [
                    'account'      => $account->account,
                    'amount'       => 0,
                    'rate'         => $rate->rate,
                    'total_amount' => 0,
                    'status'       => 'failed',
                    'msg'          => $matchedItem['msg'] ?? '兑换失败',
                    'details'      => json_encode([
                        'card_number'  => $code,
                        'card_type'    => $this->cardType,
                        'country_code' => $this->countryCode,
                        'api_response' => $result
                    ])
                ],
                'wechat_msg' => $failureWechatMsg
            ];
        }
    }

    /**
     * 解析余额字符串中的数值
     *
     * @param string $balanceStr 余额字符串，如"$100.00"
     * @return float 解析后的数值
     */
    protected function parseBalance(string $balanceStr): float
    {
        // 移除货币符号和非数字字符，只保留数字和小数点
        $numericStr = preg_replace('/[^0-9.]/', '', $balanceStr);
        return (float)$numericStr;
    }

    /**
     * 调用兑换API
     * @throws Exception
     */
    private function callExchangeApi(ItunesTradeAccount $account, ItunesTradePlan $plan, string $code): array
    {
        $this->getLogger()->info("开始调用兑换API", [
            'account' => $account->account,
            'code'    => $code,
            'plan_id' => $plan->id
        ]);

        // 创建兑换任务
        $redemptionData = [
            [
                'username'   => $account->account ?? '',
                'password'   => $account->getDecryptedPassword(),
                'verify_url' => $account->api_url ?? '',
                'pin'        => $code
            ]
        ];

        $giftCardApiClient = new GiftCardApiClient();

        try {
            // 创建兑换任务
            $redemptionTask = $giftCardApiClient->createRedemptionTask($redemptionData, $plan->exchange_interval);

            $this->getLogger()->info("创建兑换请求原始返回消息", [
                'account'  => $account->account,
                'code'     => $code,
                'response' => $redemptionTask
            ]);

            if ($redemptionTask['code'] !== 0) {
                throw new Exception('创建兑换任务失败: ' . ($redemptionTask['msg'] ?? '未知错误'));
            }

            $taskId = $redemptionTask['data']['task_id'] ?? null;
            if (empty($taskId)) {
                throw new Exception('创建兑换任务失败: 未获取到任务ID');
            }

            $this->getLogger()->info('兑换任务创建成功', ['task_id' => $taskId]);

            // 等待任务完成
            return $this->waitForTaskCompletion($giftCardApiClient, $taskId);

        } catch (Exception $e) {
            $this->getLogger()->error("兑换API调用失败", [
                'account' => $account->account,
                'code'    => $code,
                'error'   => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 等待任务完成
     */
    private function waitForTaskCompletion(GiftCardApiClient $giftCardApiClient, string $taskId): array
    {
        $maxAttempts       = 500;        // 最大尝试次数
        $sleepMicroseconds = 200 * 1000; // 200毫秒
        $timeoutSeconds    = 120;        // 2分钟超时
        $startTime         = time();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // 检查超时
            if (time() - $startTime > $timeoutSeconds) {
                $this->getLogger()->error('兑换任务执行超时', [
                    'task_id' => $taskId,
                    'timeout_seconds' => $timeoutSeconds,
                    'attempts' => $attempt,
                    'gift_card_code' => $this->giftCardCode
                ]);
                throw new Exception("兑换任务执行超时（{$timeoutSeconds}秒），任务ID: {$taskId}");
            }

            try {
                $redeemResult = $giftCardApiClient->getRedemptionTaskStatus($taskId);

                if ($redeemResult['code'] !== 0) {
                    $this->getLogger()->error('查询兑换任务状态失败', [
                        'task_id' => $taskId,
                        'attempt' => $attempt,
                        'error'   => $redeemResult['msg'] ?? '未知错误',
                        'gift_card_code' => $this->giftCardCode
                    ]);

                    // 如果是网络错误，继续重试
                    if ($attempt < $maxAttempts) {
                        usleep($sleepMicroseconds);
                        continue;
                    } else {
                        throw new Exception('查询兑换任务状态失败: ' . ($redeemResult['msg'] ?? '未知错误'));
                    }
                }

                // 验证数据结构
                if (!isset($redeemResult['data']) || !is_array($redeemResult['data'])) {
                    $this->getLogger()->error('兑换任务状态数据结构无效', [
                        'task_id'  => $taskId,
                        'attempt'  => $attempt,
                        'response' => $redeemResult,
                        'gift_card_code' => $this->giftCardCode
                    ]);

                    if ($attempt < $maxAttempts) {
                        usleep($sleepMicroseconds);
                        continue;
                    } else {
                        throw new Exception('兑换任务状态数据结构无效');
                    }
                }

                $status = $redeemResult['data']['status'] ?? '';

                // 定期记录任务状态（每10次记录一次）
                if ($attempt % 10 === 0 || $attempt <= 5) {
                    $this->getLogger()->info('当前兑换任务状态', [
                        'task_id' => $taskId,
                        'attempt' => $attempt,
                        'status'  => $status,
                        'gift_card_code' => $this->giftCardCode
                    ]);
                }

                if ($status === 'completed') {
                    $this->getLogger()->info('兑换任务完成', [
                        'task_id'  => $taskId,
                        'attempt'  => $attempt,
                        'response' => $redeemResult,
                        'gift_card_code' => $this->giftCardCode
                    ]);

                    // 验证完成状态的数据结构
                    if (!isset($redeemResult['data']['items']) || !is_array($redeemResult['data']['items'])) {
                        throw new Exception('任务完成但数据结构无效');
                    }

                    // 处理每个item的result字段
                    $this->processTaskResults($redeemResult);

                    return $redeemResult;

                } elseif ($status === 'failed') {
                    $errorMsg = $redeemResult['data']['msg'] ?? '未知原因';
                    $this->getLogger()->error('兑换任务执行失败', [
                        'task_id' => $taskId,
                        'attempt' => $attempt,
                        'error'   => $errorMsg,
                        'gift_card_code' => $this->giftCardCode
                    ]);
                    throw new Exception('兑换任务执行失败: ' . $errorMsg);

                } elseif ($status === 'running' || $status === 'pending') {
                    // 任务仍在执行中，继续等待
                    usleep($sleepMicroseconds);
                    continue;

                } else {
                    // 未知状态，记录日志但继续等待
                    $this->getLogger()->warning('未知的任务状态', [
                        'task_id' => $taskId,
                        'attempt' => $attempt,
                        'status'  => $status,
                        'gift_card_code' => $this->giftCardCode
                    ]);
                    usleep($sleepMicroseconds);
                    continue;
                }

            } catch (Exception $e) {
                $this->getLogger()->error('查询任务状态时发生异常', [
                    'task_id' => $taskId,
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                    'gift_card_code' => $this->giftCardCode
                ]);

                // 如果不是最后一次尝试，继续重试
                if ($attempt < $maxAttempts) {
                    usleep($sleepMicroseconds * 2); // 异常时等待更长时间
                    continue;
                } else {
                    throw $e;
                }
            }
        }

        throw new Exception("兑换任务执行超时或达到最大重试次数，任务ID: {$taskId}，礼品卡: {$this->giftCardCode}");
    }

    /**
     * 处理任务结果，解析JSON字符串
     */
    private function processTaskResults(array &$redeemResult): void
    {
        foreach ($redeemResult['data']['items'] as &$item) {
            if (isset($item['result']) && is_string($item['result'])) {
                try {
                    $decodedResult = json_decode($item['result'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $item['result'] = $decodedResult;
                    } else {
                        $this->getLogger()->warning('解析result JSON失败', [
                            'json_error'      => json_last_error_msg(),
                            'original_result' => $item['result']
                        ]);
                    }
                } catch (Exception $e) {
                    $this->getLogger()->error('解析result JSON时发生异常', [
                        'error'           => $e->getMessage(),
                        'original_result' => $item['result']
                    ]);
                }
            }
        }
    }

    /**
     * 检查并更新天数完成情况
     *
     * 这个方法的作用：
     * 1. 检查当天是否还有待处理的任务 - 确保一天的所有兑换任务都完成后才更新状态
     * 2. 检查当天兑换金额是否达到计划要求 - 只有达到要求才能进入下一天
     * 3. 更新账号的completed_days字段 - 记录每天的累计兑换金额
     * 4. 判断是否进入下一天或完成整个计划
     */
    protected function checkAndUpdateDayCompletion(ItunesTradeAccount $account, ItunesTradePlan $plan): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        // 更新completed_days字段
        $completedDays                      = json_decode($account->completed_days ?? '{}', true) ?: [];
        $completedDays[(string)$currentDay] = $currentDay;
        $account->update([
            'completed_days' => json_encode($completedDays),
        ]);
        // 检查当天是否还有其他待处理的任务
        // 这一步很重要：因为可能同时有多个兑换任务在执行，需要确保当天所有任务都完成
        $pendingCount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_PENDING)
            ->count();

        if ($pendingCount == 0) {
            // 当天任务全部完成，计算当天的累计兑换金额
            $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $currentDay)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');

            // 获取当天的计划额度
            $dailyAmounts = $plan->daily_amounts ?? [];
            $dailyLimit   = $dailyAmounts[$currentDay - 1] ?? 0;
            // $dailyTarget = $dailyLimit + $plan->float_amount; // 计划额度 + 浮动额度
            $dailyTarget = $dailyLimit;
            $this->getLogger()->info("检查当天完成情况", [
                'account_id'        => $account->account,
                'day'               => $currentDay,
                'daily_amount'      => $dailyAmount,
                'daily_limit'       => $dailyLimit,
                'float_amount'      => $plan->float_amount,
                'daily_target'      => $dailyTarget,
                'is_target_reached' => $dailyAmount >= $dailyTarget
            ]);

            // 更新completed_days字段
            $completedDays                      = json_decode($account->completed_days ?? '{}', true) ?: [];
            $completedDays[(string)$currentDay] = $dailyAmount;

            // 检查是否达到当天的目标额度
            if ($dailyAmount >= $dailyTarget) {
                // 达到目标额度，可以进入下一天或完成计划
                $this->getLogger()->info("当天目标达成，更新账号状态", [
                    'account_id'     => $account->id,
                    'day'            => $currentDay,
                    'daily_amount'   => $dailyAmount,
                    'daily_target'   => $dailyTarget,
                    'completed_days' => $completedDays
                ]);

                if ($currentDay >= $plan->plan_days) {
                    // 计划完成
                    $account->update([
                        'status'           => ItunesTradeAccount::STATUS_COMPLETED,
                        'current_plan_day' => null,
                        'plan_id'          => null,
                        'completed_days'   => json_encode($completedDays),
                    ]);

                    $this->getLogger()->info("账号计划完成", [
                        'account_id'           => $account->id,
                        'plan_id'              => $plan->id,
                        'total_completed_days' => count($completedDays),
                        'final_completed_days' => $completedDays
                    ]);
                } else {
                    // 进入下一天，但需要等待日期间隔
                    $nextDay = $currentDay + 1;
                    $account->update([
                        'status'           => ItunesTradeAccount::STATUS_WAITING,
                        'current_plan_day' => $nextDay,
                        'completed_days'   => json_encode($completedDays),
                    ]);

                    $this->getLogger()->info("账号进入下一天", [
                        'account_id'     => $account->id,
                        'current_day'    => $nextDay,
                        'plan_id'        => $plan->id,
                        'completed_days' => $completedDays
                    ]);
                }
            } else {
                // 未达到目标额度，保持当前状态，等待更多兑换
                $account->update([
                    'status'         => ItunesTradeAccount::STATUS_LOCKING,
                    'completed_days' => json_encode($completedDays),
                ]);

                $this->getLogger()->info("当天目标未达成，保持等待状态", [
                    'account_id'       => $account->id,
                    'day'              => $currentDay,
                    'daily_amount'     => $dailyAmount,
                    'daily_target'     => $dailyTarget,
                    'remaining_amount' => $dailyTarget - $dailyAmount,
                    'completed_days'   => $completedDays
                ]);
            }
        } else {
            $this->getLogger()->info("当天还有待处理任务，暂不更新状态", [
                'account_id'    => $account->id,
                'current_day'   => $currentDay,
                'pending_count' => $pendingCount
            ]);
        }
    }

    /**
     * 判断是否为系统错误（需要记录堆栈跟踪）
     */
    protected function isSystemError(Exception $e): bool
    {
        // 如果是自定义的礼品卡兑换异常，使用其内置的判断方法
        if ($e instanceof GiftCardExchangeException) {
            return $e->isSystemError();
        }

        $message = $e->getMessage();

        // 检查是否为业务逻辑错误
        foreach (self::BUSINESS_ERRORS as $businessError) {
            // 使用不区分大小写的匹配，提高匹配准确性
            if (stripos($message, $businessError) !== false) {
                return false; // 是业务错误，不需要堆栈跟踪
            }
        }

        // 其他错误视为系统错误，需要堆栈跟踪
        return true;
    }

    /**
     * 判断是否为业务逻辑错误（用于其他地方的错误分类）
     *
     * @param Exception $e 异常对象
     * @return bool true=业务错误, false=系统错误
     */
    protected function isBusinessError(Exception $e): bool
    {
        return !$this->isSystemError($e);
    }

    /**
     * 检查礼品卡状态（不执行兑换）
     */
    public function check(string $code): array
    {
        try {
            return $this->validateGiftCard($code);
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 检查是否为登录失败错误
     *
     * @param Exception $e 异常对象
     * @return bool true=登录失败错误, false=其他错误
     */
    private function isLoginFailureError(Exception $e): bool
    {
        $message = $e->getMessage();

        // 登录失败相关的错误模式
        $loginFailurePatterns = [
            '登录失败',
            'redis: nil',
            'login failed',
            'need login',
            'session expired',
            'authentication failed',
            'invalid credentials',
            'unauthorized',
            'login required'
        ];

        foreach ($loginFailurePatterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /*
     * ============================================================================
     * GiftCardService 重构总结 - 2024-12-19
     * ============================================================================
     *
     * 主要变更：
     * 1. 新增 FindAccountService 依赖注入，使用高性能6层交集筛选机制
     * 2. 重构 findAvailableAccount() 方法，调用 FindAccountService.findOptimalAccount()
     * 3. 注释保留以下旧方法（保留代码以防回滚）：
     *    - findAvailableAccount_OLD()     - 原始账号查找逻辑
     *    - validateAndLockAccount()       - 账号验证和锁定
     *    - getAllCandidateAccounts()      - 候选账号获取
     *    - sortAccountsByPriority()       - 优先级排序
     *    - validateAccount()              - 账号验证
     *    - validateDailyAmount()          - 每日额度验证
     *    - validateTotalAmount()          - 总额度验证
     *    - findWaitingAccount()           - 等待账号查找
     *    - findProcessingAccount()        - 处理中账号查找
     *
     * 性能提升：
     * - 从逐个验证改为交集筛选，理论性能提升5-10倍
     * - SQL层面的批量查询和优先级排序
     * - 原子锁定机制内置在FindAccountService中
     *
     * 向后兼容：
     * - 保持相同的方法签名和返回值
     * - 异常处理逻辑不变
     * - 日志输出格式兼容
     */
}
