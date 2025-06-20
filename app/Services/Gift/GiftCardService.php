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
    protected string $roomId;
    protected string $code;
    protected string $cardType;
    protected string $cardForm;
    protected string $countryCode;
    protected string $msgid;

    public function __construct(GiftCardExchangeService $exchangeService)
    {
        $this->exchangeService = $exchangeService;
    }

    /**
     * 获取礼品卡兑换专用日志实例
     */
    protected function getLogger(): LoggerInterface
    {
        return Log::channel('gift_card_exchange');
    }

    /**
     * 兑换礼品卡
     * @throws Exception
     */
    public function redeem(string $code, string $roomId, string $cardType, string $cardForm, string $batchId, string $msgid = ''): array
    {
        $this->getLogger()->info("开始兑换礼品卡", [
            'code' => $code,
            'room_id' => $roomId,
            'card_type' => $cardType,
            'card_form' => $cardForm,
            'batch_id' => $batchId,
            'msgid' => $msgid
        ]);
        $this->roomId = $roomId;
        $this->code = $code;
        $this->cardType = $cardType;
        $this->cardForm = $cardForm;
        $this->msgid = $msgid;

        try {
            // 1. 验证礼品卡并获取信息
            $giftCardInfo = $this->validateGiftCard($code);
            $this->getLogger()->info('验证礼品卡并获取信息', $giftCardInfo);

            // 2. 根据条件获取汇率
            $rate = $this->findMatchingRate($giftCardInfo, $roomId, $cardType, $cardForm);
            $this->getLogger()->info('根据条件获取汇率', $rate->toArray());
            // 3. 获取对应的计划
            $plan = $this->findAvailablePlan($rate->id);
            $this->getLogger()->info('获取对应的计划', $plan->toArray());
            // 4. 查找符合要求的账号
            $account = $this->findAvailableAccount($plan, $roomId, $giftCardInfo);
            $this->getLogger()->info('获取对应的账号', $account->toArray());
            // 5. 执行兑换
            $result = $this->executeRedemption($code, $giftCardInfo, $rate, $plan, $account, $batchId);

            $this->getLogger()->info("礼品卡兑换成功", [
                'code' => $code,
                'result' => $result,
                'batch_id' => $batchId
            ]);

            return $result;

        } catch (Exception $e) {
            // 根据错误类型决定是否记录堆栈跟踪
            $logData = [
                'code' => $code,
                'error' => $e->getMessage(),
                'batch_id' => $batchId
            ];

            // 只有系统错误才记录堆栈跟踪，业务逻辑错误不记录
            if ($this->isSystemError($e)) {
                $logData['trace'] = $e->getTraceAsString();
            }

            $this->getLogger()->error("礼品卡兑换失败", $logData);

            throw $e;
        }
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
        if (!$result['is_valid'] || $result['balance']<=0 ) {
            throw new Exception("礼品卡无效: " . ($result['message'] ?? '未知错误'));
        }
        $this->countryCode =  $result['country_code'];
        return [
            'country_code' => $result['country_code'],
            'amount' => $result['balance'],
            'currency' => $result['currency'] ?? 'USD',
            'valid' => true
        ];
    }

    /**
     * 查找匹配的汇率
     * @throws Exception
     */
    protected function findMatchingRate(array $giftCardInfo, string $roomId, string $cardType, string $cardForm): ItunesTradeRate
    {
        $countryCode = $giftCardInfo['country_code'];
        $amount = $giftCardInfo['amount'];

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
                    'parsed_fixed_amounts' => $fixedAmounts,
                    'amount' => $amount,
                    'amount_type' => gettype($amount),
                    'type' => gettype($fixedAmounts)
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
                                    'amount' => $amount,
                                    'matched_value' => $fixedAmount,
                                    'diff' => abs($amount - (float)$fixedAmount)
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
            }

            // 2. 检查倍数要求
            elseif ($rate->amount_constraint === ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE) {
                $multipleBase = $rate->multiple_base ?? 1;
                $minAmount = $rate->min_amount ?? 0;
                $maxAmount = ($rate->max_amount > 0) ? $rate->max_amount : PHP_FLOAT_MAX;

                $this->getLogger()->info("倍数要求检查", [
                    'amount' => $amount,
                    'multiple_base' => $multipleBase,
                    'min_amount' => $minAmount,
                    'max_amount' => $maxAmount,
                    'modulo_result' => $amount % $multipleBase
                ]);

                if ($amount % $multipleBase == 0 &&
                    $amount >= $minAmount &&
                    $amount <= $maxAmount) {
                    $this->getLogger()->info("匹配到倍数要求汇率", ['rate_id' => $rate->id]);
                    return $rate;
                }
            }

            // 3. 检查全面额
            elseif ($rate->amount_constraint === ItunesTradeRate::AMOUNT_CONSTRAINT_ALL) {
                $minAmount = $rate->min_amount ?? 0;
                $maxAmount = ($rate->max_amount > 0) ? $rate->max_amount : PHP_FLOAT_MAX;

                $this->getLogger()->info("全面额检查", [
                    'amount' => $amount,
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
        if($roomInfo) $roomId = $roomInfo->id;
        return MrRoomGroup::whereRaw("FIND_IN_SET(?, room_ids)", [$roomId])->value('id');
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
     * 查找可用账号
     */
    protected function findAvailableAccount(
        ItunesTradePlan $plan,
        string $roomId,
        array $giftCardInfo,
        int $lastCheckedId = 0
    ): ItunesTradeAccount {
        $this->getLogger()->info("开始查找可用账号", [
            'plan_id' => $plan->id,
            'room_id' => $roomId,
            'last_checked_id' => $lastCheckedId,
            'bind_room' => $plan->bind_room
        ]);

        // 1. 优先查找状态为processing的账号
        $account = $this->findProcessingAccount($plan, $roomId, $lastCheckedId);
        if ($account) {
            $this->getLogger()->info("找到processing状态账号", ['account_id' => $account->id]);
            if ($this->validateAndLockAccount($account, $plan, $giftCardInfo)) {
                return $account;
            } else {
                $this->getLogger()->info("processing账号验证失败或锁定失败，继续查找", ['account_id' => $account->id]);
                // 递归查找下一个账号
                return $this->findAvailableAccount($plan, $roomId, $giftCardInfo, $account->id);
            }
        }

        // 2. 查找状态为waiting且天数为1的账号，
        // 暂时注释 06-18，waiting状态将在计划任务中执行
//        $account = $this->findWaitingAccount($plan, $lastCheckedId);
//        if ($account) {
//            $this->getLogger()->info("找到waiting状态账号", ['account_id' => $account->id]);
//            if ($this->validateAndLockAccount($account, $plan, $giftCardInfo)) {
//                return $account;
//            } else {
//                $this->getLogger()->info("waiting账号验证失败或锁定失败，继续查找", ['account_id' => $account->id]);
//                // 递归查找下一个账号
//                return $this->findAvailableAccount($plan, $roomId, $giftCardInfo, $account->id);
//            }
//        }

        throw new Exception("未找到可用的兑换账号");
    }

    /**
     * 验证账号并尝试原子锁定
     */
    private function validateAndLockAccount(
        ItunesTradeAccount $account,
        ItunesTradePlan $plan,
        array $giftCardInfo
    ): bool {
        // 首先验证账号是否符合条件
        if (!$this->validateAccount($account, $plan, $giftCardInfo)) {
            return false;
        }

        // 尝试原子锁定账号 - 使用数据库级别的原子操作
        $originalStatus = $account->status;
        $lockResult = DB::table('itunes_trade_accounts')
            ->where('id', $account->id)
            ->where('status', $originalStatus) // 确保状态没有被其他任务改变
            ->update([
                'status' => ItunesTradeAccount::STATUS_LOCKING,
                'plan_id' => $plan->id,
                'updated_at' => now()
            ]);

        if ($lockResult > 0) {
            // 锁定成功，刷新账号模型
            $account->refresh();
            $this->getLogger()->info("账号原子锁定成功", [
                'account_id' => $account->id,
                'original_status' => $originalStatus,
                'locked_status' => ItunesTradeAccount::STATUS_LOCKING
            ]);
            return true;
        } else {
            // 锁定失败，说明账号状态已被其他任务改变
            $this->getLogger()->info("账号原子锁定失败，可能已被其他任务占用", [
                'account_id' => $account->id,
                'original_status' => $originalStatus
            ]);
            return false;
        }
    }

    /**
     * 查询执行中的账号
     */
    private function findProcessingAccount(
        ItunesTradePlan $plan,
        string $roomId,
        int $lastCheckedId
    ): ?ItunesTradeAccount {
        $query = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->where('id', '>', $lastCheckedId)
            ->orderBy('id', 'asc');

        // 如果计划绑定群聊，优先查找绑定该群聊的账号
        if ($plan->bind_room && !empty($roomId)) {
            $account = (clone $query)->where('room_id', $roomId)->first();
            if ($account) {
                $this->getLogger()->info("找到绑定群聊的processing账号", [
                    'account_id' => $account->id,
                    'room_id' => $roomId
                ]);
                return $account;
            }
        }

        return $query->first();
    }

    /**
     * 查找等待状态且处于第一天的账号
     */
    private function findWaitingAccount(
        ItunesTradePlan $plan,
        int $lastCheckedId
    ): ?ItunesTradeAccount {
        return ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_WAITING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->where('current_plan_day', 1)
            ->where('id', '>', $lastCheckedId)
            ->orderBy('id', 'asc')
            ->first();
    }

    /**
     * 验证账号是否可用
     */
    private function validateAccount(
        ItunesTradeAccount $account,
        ItunesTradePlan $plan,
        array $giftCardInfo
    ): bool {
        // 检查账号是否被锁定
        if ($account->status === ItunesTradeAccount::STATUS_LOCKING) {
            $this->getLogger()->info("账号已被锁定，跳过", [
                'account_id' => $account->id,
                'status' => $account->status
            ]);
            return false;
        }

        // 检查当日额度
        if (!$this->validateDailyAmount($account, $plan, $giftCardInfo)) {
            return false;
        }

        // 检查总额度
        if (!$this->validateTotalAmount($account, $plan, $giftCardInfo)) {
            return false;
        }

        // 检查国家匹配
        if (!empty($account->country_code) &&
            $account->country_code !== $giftCardInfo['country_code']) {
            $this->getLogger()->info("账号国家不匹配", [
                'account_id' => $account->id,
                'account_country' => $account->country_code,
                'card_country' => $giftCardInfo['country_code']
            ]);
            return false;
        }

        return true;
    }

    /**
     * 验证当天已兑总额
     */
    private function validateDailyAmount(
        ItunesTradeAccount $account,
        ItunesTradePlan $plan,
        array $giftCardInfo
    ): bool {
        $currentDay = $account->current_plan_day ?? 1;

        // 获取当天已成功兑换的总额
        $dailySpent = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        // 获取当天的计划额度
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;

        // 计算可用额度（计划额度 + 浮动额度 - 已使用额度）
        $availableDailyAmount = $dailyLimit + $plan->float_amount - $dailySpent;
        $requiredAmount = $giftCardInfo['amount'];

        $isValid = $availableDailyAmount >= $requiredAmount;

        $this->getLogger()->info(
            $isValid ? "当日额度验证通过" : "当日额度不足",
            [
                'account_id' => $account->account,
                'current_day' => $currentDay,
                'daily_limit' => $dailyLimit,
                'float_amount' => $plan->float_amount,
                'daily_spent' => $dailySpent,
                'available_daily_amount' => $availableDailyAmount,
                'required_amount' => $requiredAmount,
            ]
        );

        return $isValid;
    }

    /**
     * 检查账户总额度
     */
    private function validateTotalAmount(
        ItunesTradeAccount $account,
        ItunesTradePlan $plan,
        array $giftCardInfo
    ): bool {
        // 获取账号已成功兑换的总额
        $totalSpent = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        // 检查加上当前兑换金额后是否超过总限额
        $totalAfterExchange = $totalSpent + $giftCardInfo['amount'];
        $isValid = $totalAfterExchange <= $plan->total_amount;

        $this->getLogger()->info(
            $isValid ? "总额度验证通过" : "超出总额度限制",
            [
                'account_id' => $account->account,
                'total_spent' => $totalSpent,
                'current_amount' => $giftCardInfo['amount'],
                'total_after_exchange' => $totalAfterExchange,
                'total_amount_limit' => $plan->total_amount,
            ]
        );

        return $isValid;
    }

    /**
     * 执行兑换
     */
    protected function executeRedemption(
        string $code,
        array $giftCardInfo,
        ItunesTradeRate $rate,
        ItunesTradePlan $plan,
        ItunesTradeAccount $account,
        string $batchId
    ): array {
        return DB::transaction(function () use ($code, $giftCardInfo, $rate, $plan, $account, $batchId) {
            $this->getLogger()->info("开始执行兑换", [
                'code' => $code,
                'account_id' => $account->account,
                'plan_id' => $plan->id,
                'rate_id' => $rate->id,
                'account_status' => $account->status
            ]);

            // 账号已经在findAvailableAccount阶段被锁定为STATUS_LOCKING
            // 这里只需要记录锁定前的状态，用于失败时恢复
            // 通过completed_days字段来推断原始状态
            $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
            $currentDay = $account->current_plan_day ?? 1;

            // 推断原始状态：如果是第一天且没有完成记录，则为WAITING；否则为PROCESSING
            $originalStatus = (empty($completedDays) && $currentDay == 1)
                ? ItunesTradeAccount::STATUS_WAITING
                : ItunesTradeAccount::STATUS_PROCESSING;

            $this->getLogger()->info("账号已在查找阶段锁定", [
                'account_id' => $account->id,
                'code' => $code,
                'current_status' => $account->status,
                'inferred_original_status' => $originalStatus
            ]);

            // 创建兑换日志
            $log = ItunesTradeAccountLog::create([
                'account_id' => $account->id,
                'plan_id' => $plan->id,
                'code' => $code,
                'day' => $account->current_plan_day ?? 1,
                'amount' => $giftCardInfo['amount'],
                'status' => ItunesTradeAccountLog::STATUS_PENDING,
                'rate_id' => $rate->id,
                'country_code' => $giftCardInfo['country_code'],
                'exchange_time' => now(),
            ]);

            // 触发日志创建事件
            event(new TradeLogCreated($log));

            try {
                // 调用兑换API
                $exchangeResult = $this->callExchangeApi($account, $plan, $code);
                if (!$exchangeResult) {
                    throw new Exception("兑换API返回数据格式错误");
                }

                // 解析兑换结果
                $exchangeData = $this->parseExchangeResult($exchangeResult, $account, $rate, $code);

                if ($exchangeData['success']) {
                    // 更新日志状态为成功，注意：amount字段在创建时已经设置为礼品卡面额
                    // 这里不需要重复更新amount字段，只更新状态即可
                    $log->update([
                        'status' => ItunesTradeAccountLog::STATUS_SUCCESS,
                        'error_message' => null,
                    ]);

                    // 更新账号余额为API返回的总金额
                    $currentAmount = $exchangeData['data']['amount'] ?? 0;
                    $totalAmount = $exchangeData['data']['total_amount'] ?? 0;
                    if ($currentAmount > 0) {
                        // 更新completed_days字段
                        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
                        $completedDays[(string)$currentDay] = $currentAmount;
                        $account->update([
                            'amount' => $totalAmount,  // 更新为ID总金额
                             'completed_days' => json_encode($completedDays),
                        ]);

                        $this->getLogger()->info("更新账号余额", [
                            'account_id' => $account->account,
                            'account' => $account->account,
                            'new_amount' => $totalAmount,
                            'fund_added' => $exchangeData['data']['amount'] ?? 0
                        ]);
                    }

                    // 兑换成功，保持锁定状态不变，让计划任务来处理状态转换
                    $this->getLogger()->info("兑换成功，保持锁定状态", [
                        'code' => $code,
                        'account_id' => $account->account,
                        'status_kept' => 'LOCKING (待计划任务处理)',
                        'original_amount' => $giftCardInfo['amount'],
                        'exchanged_amount' => $exchangeData['data']['amount'] ?? 0
                    ]);

                    // 触发日志更新事件
                    event(new TradeLogCreated($log->fresh()));

                    // 注意：不调用 checkAndUpdateDayCompletion，让计划任务统一处理
                    // $this->checkAndUpdateDayCompletion($account, $plan);

                    // 执行加账处理
                    $buildWechatMsg = $this->processAccountBilling($giftCardInfo['amount'], $rate, $account);
                    // 返回成功结果
                    return [
                        'success' => true,
                        'log_id' => $log->id,
                        'account_id' => $account->id,
                        'account_username' => $account->account,
                        'plan_id' => $plan->id,
                        'rate_id' => $rate->id,
                        'country_code' => $giftCardInfo['country_code'],
                        'original_amount' => $giftCardInfo['amount'],
                        'exchanged_amount' => $exchangeData['data']['amount'] ?? 0,
                        'rate' => $rate->rate,
                        'total_amount' => $exchangeData['data']['total_amount'] ?? 0,
                        'currency' => $giftCardInfo['currency'] ?? 'USD',
                        'exchange_time' => $log->exchange_time->toISOString(),
                        'message' => $exchangeData['message'],
                        'details' => $exchangeData['data']['details'] ?? null,
                        'wechat_msg' => $buildWechatMsg
                    ];
                } else {
                    // 兑换失败，更新日志
                    $log->update([
                        'status' => ItunesTradeAccountLog::STATUS_FAILED,
                        'error_message' => $exchangeData['message']
                    ]);

                    $this->getLogger()->error("兑换失败", [
                        'code' => $code,
                        'account_id' => $account->account,
                        'error' => $exchangeData['message']
                    ]);

                    // 兑换失败，恢复账号到锁定前的状态
//                    $account->update([
//                        'status' => $originalStatus,
//                    ]);
//
//                    $this->getLogger()->info("兑换失败，恢复账号状态", [
//                        'account_id' => $account->id,
//                        'status_restored' => "LOCKING -> {$originalStatus}",
//                        'error' => $exchangeData['message']
//                    ]);

                    // 触发日志更新事件
                    event(new TradeLogCreated($log->fresh()));

                    throw new Exception("兑换失败: " . $exchangeData['message']);
                }

            } catch (Exception $e) {
                // 处理异常情况
                $log->update([
                    'status' => ItunesTradeAccountLog::STATUS_FAILED,
                    'error_message' => $e->getMessage()
                ]);

                // 异常情况，恢复账号到锁定前的状态
//                $account->update([
//                    'status' => $originalStatus,
//                ]);

//                $this->getLogger()->error("兑换过程发生异常，恢复账号状态", [
//                    'code' => $code,
//                    'account_id' => $account->id,
//                    'status_restored' => "LOCKING -> {$originalStatus}",
//                    'error' => $e->getMessage(),
//                    'trace' => $this->isSystemError($e) ? $e->getTraceAsString() : null
//                ]);

                // 触发日志更新事件
                event(new TradeLogCreated($log->fresh()));

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
            $amount = bcadd($amount, '0', 2); // 确保金额为两位小数
            $rate = bcadd($rateObj->rate, '0', 2); // 确保汇率为两位小数

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
                    'room_id' => $this->roomId,
                    'room_name' => $room->room_name ?? '未知群组',
                    'event' => 1, // 兑换事件
                    'msgid' => $this->msgid,
                    'money' => $amount,
                    'rate' => $rate,
                    'fee' => 0.00,
                    'amount' => $changeAmount,
                    'card_type' => $countryCode, // 国家
                    'before_money' => $beforeMoney,
                    'bill_money' => $afterMoney, // 修正：这应该是变动后的总金额
                    'remark' => 'iTunes',
                    'op_id' => '',
                    'op_name' => '',
                    'code' => $this->code,
                    'content' => json_encode([
                        'account' => $account->account,
                        'original_amount' => $amount,
                        'exchange_rate' => $rate,
                        'converted_amount' => $changeAmount
                    ]),
                    'note' => "礼品卡兑换 - {$this->code}",
                    'status' => 0,
                    'is_settle' => 0,
                    'is_del' => 0
                ]);

                // 更新群组未结算金额和变更时间
                $room->update([
                    'unsettled' => $afterMoney,
                    'changed_at' => now()
                ]);

                // 提交事务
                DB::commit();

                // 构建成功消息
                $successMessage = $this->buildSuccessMessage([
                    'card_number' => $this->code,
                    'amount' => $amount,
                    'rate' => $rate,
                    'before_money' => $beforeMoney,
                    'change_amount' => $changeAmount,
                    'after_money' => $afterMoney,
                    'exchange_time' => now()->format('Y-n-j H:i:s')
                ]);



                Log::channel('gift_card_exchange')->info("群组 {$this->roomId} 加账处理完成", [
                    'msgid' => $this->msgid,
                    'card_number' =>  $this->code,
                    'amount' => $amount,
                    'rate' => $rate,
                    'change_amount' => $changeAmount,
                    'before_money' => $beforeMoney,
                    'after_money' => $afterMoney,
                    'success_msg' => $successMessage,
                    'room_id' => $this->roomId
                ]);



            } catch (\Exception $e) {
                // 回滚事务
                DB::rollBack();
                throw $e;
            }
            return $successMessage;
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error("加账处理失败: " . $e->getMessage(), [
                'room_id' => $this->input['room_id'] ?? '',
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
        $amount = bcadd($data['amount'], '0', 2);
        $beforeMoney = bcadd($data['before_money'], '0', 2);
        $rate = bcadd($data['rate'], '0', 2); // 汇率保留一位小数
        $changeAmount = bcadd($data['change_amount'], '0', 2); // 变动金额保留整数
        $afterMoney = bcadd($data['after_money'], '0', 2);

        return sprintf(
            "[强]兑换成功\n" .
            "---------------------------------\n" .
            "加载卡号：%s\n" .
            "加载结果：$%s（%s）\n" .
            "原始账单：%s\n" .
            "变动金额：%s%s*%s=%s\n" .
            "当前账单：%s\n" .
            "加卡时间：%s",
            $data['card_number'],
            $amount,
            $this->countryCode,
            $beforeMoney,
            $this->countryCode,
            $amount,
            $rate,
            $changeAmount,
            $afterMoney,
            $data['exchange_time']
        );
    }

    /**
     * 解析兑换结果
     */
    private function parseExchangeResult(array $exchangeResult, ItunesTradeAccount $account, ItunesTradeRate $rate, string $code): array
    {
        // 检查基本数据结构
        if (!isset($exchangeResult['data']['items']) || !is_array($exchangeResult['data']['items'])) {
            return [
                'success' => false,
                'message' => '兑换结果数据结构错误',
                'data' => []
            ];
        }

        // 查找匹配的兑换项
        $dataId = $account->account . ":" . $code;
        $matchedItem = null;

        foreach ($exchangeResult['data']['items'] as $item) {
            if (isset($item['data_id']) && $item['data_id'] === $dataId) {
                $matchedItem = $item;
                break;
            }
        }

        if (!$matchedItem) {
            return [
                'success' => false,
                'message' => '未找到匹配的兑换项目',
                'data' => []
            ];
        }

        $this->getLogger()->info('兑换任务item详情：', $matchedItem);

        // 检查兑换结果
        $result = $matchedItem['result'] ?? [];
        $resultCode = $result['code'] ?? -1;

        if ($resultCode === 0) {
            // 兑换成功
            $amount = $this->parseBalance($result['fund'] ?? '0');
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
                'data' => [
                    'account' => $account->account,
                    'amount' => $amount,
                    'rate' => $rate->rate,
                    'total_amount' => $totalAmount,
                    'status' => 'success',
                    'msg' => $matchedItem['msg'] ?? '',
                    'details' => json_encode([
                        'card_number' => $code,
                        'card_type' => $this->cardType,
                        'country_code' => $this->countryCode,
                        'api_response' => $result
                    ])
                ]
            ];
        } else {
            // 兑换失败
            return [
                'success' => false,
                'message' => sprintf(
                    "%s:%s兑换失败\n原因：%s",
                    $account->account,
                    $code,
                    $matchedItem['msg'] ?? '未知原因'
                ),
                'data' => [
                    'account' => $account->account,
                    'amount' => 0,
                    'rate' => $rate->rate,
                    'total_amount' => 0,
                    'status' => 'failed',
                    'msg' => $matchedItem['msg'] ?? '兑换失败',
                    'details' => json_encode([
                        'card_number' => $code,
                        'card_type' => $this->cardType,
                        'country_code' => $this->countryCode,
                        'api_response' => $result
                    ])
                ]
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
        return (float) $numericStr;
    }

    /**
     * 调用兑换API
     * @throws Exception
     */
    private function callExchangeApi(ItunesTradeAccount $account, ItunesTradePlan $plan, string $code): array
    {
        $this->getLogger()->info("开始调用兑换API", [
            'account' => $account->account,
            'code' => $code,
            'plan_id' => $plan->id
        ]);

        // 创建兑换任务
        $redemptionData = [
            [
                'username' => $account->account ?? '',
                'password' => $account->getDecryptedPassword(),
                'verify_url' => $account->api_url ?? '',
                'pin' => $code
            ]
        ];

        $giftCardApiClient = new GiftCardApiClient();

        try {
            // 创建兑换任务
            $redemptionTask = $giftCardApiClient->createRedemptionTask($redemptionData, $plan->exchange_interval);

            $this->getLogger()->info("创建兑换请求原始返回消息", [
                'account' => $account->account,
                'code' => $code,
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
                'code' => $code,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 等待任务完成
     */
    private function waitForTaskCompletion(GiftCardApiClient $giftCardApiClient, string $taskId): array
    {
        $maxAttempts = 500; // 最大尝试次数
        $sleepMicroseconds = 200 * 1000; // 200毫秒
        $timeoutSeconds = 120; // 2分钟超时
        $startTime = time();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // 检查超时
            if (time() - $startTime > $timeoutSeconds) {
                throw new Exception('兑换任务执行超时');
            }

            try {
                $redeemResult = $giftCardApiClient->getRedemptionTaskStatus($taskId);

                if ($redeemResult['code'] !== 0) {
                    $this->getLogger()->error('查询兑换任务状态失败', [
                        'task_id' => $taskId,
                        'attempt' => $attempt,
                        'error' => $redeemResult['msg'] ?? '未知错误'
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
                        'task_id' => $taskId,
                        'attempt' => $attempt,
                        'response' => $redeemResult
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
                        'status' => $status
                    ]);
                }

                if ($status === 'completed') {
                    $this->getLogger()->info('兑换任务完成', [
                        'task_id' => $taskId,
                        'attempt' => $attempt,
                        'response' => $redeemResult
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
                        'error' => $errorMsg
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
                        'status' => $status
                    ]);
                    usleep($sleepMicroseconds);
                    continue;
                }

            } catch (Exception $e) {
                $this->getLogger()->error('查询任务状态时发生异常', [
                    'task_id' => $taskId,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
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

        throw new Exception('兑换任务执行超时或达到最大重试次数');
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
                            'json_error' => json_last_error_msg(),
                            'original_result' => $item['result']
                        ]);
                    }
                } catch (Exception $e) {
                    $this->getLogger()->error('解析result JSON时发生异常', [
                        'error' => $e->getMessage(),
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
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
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
            $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;
            // $dailyTarget = $dailyLimit + $plan->float_amount; // 计划额度 + 浮动额度
            $dailyTarget = $dailyLimit;
            $this->getLogger()->info("检查当天完成情况", [
                'account_id' => $account->account,
                'day' => $currentDay,
                'daily_amount' => $dailyAmount,
                'daily_limit' => $dailyLimit,
                'float_amount' => $plan->float_amount,
                'daily_target' => $dailyTarget,
                'is_target_reached' => $dailyAmount >= $dailyTarget
            ]);

            // 更新completed_days字段
            $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
            $completedDays[(string)$currentDay] = $dailyAmount;

            // 检查是否达到当天的目标额度
            if ($dailyAmount >= $dailyTarget) {
                // 达到目标额度，可以进入下一天或完成计划
                $this->getLogger()->info("当天目标达成，更新账号状态", [
                    'account_id' => $account->id,
                    'day' => $currentDay,
                    'daily_amount' => $dailyAmount,
                    'daily_target' => $dailyTarget,
                    'completed_days' => $completedDays
                ]);

                if ($currentDay >= $plan->plan_days) {
                    // 计划完成
                    $account->update([
                        'status' => ItunesTradeAccount::STATUS_COMPLETED,
                        'current_plan_day' => null,
                        'plan_id' => null,
                        'completed_days' => json_encode($completedDays),
                    ]);

                    $this->getLogger()->info("账号计划完成", [
                        'account_id' => $account->id,
                        'plan_id' => $plan->id,
                        'total_completed_days' => count($completedDays),
                        'final_completed_days' => $completedDays
                    ]);
                } else {
                    // 进入下一天，但需要等待日期间隔
                    $nextDay = $currentDay + 1;
                    $account->update([
                        'status' => ItunesTradeAccount::STATUS_WAITING,
                        'current_plan_day' => $nextDay,
                        'completed_days' => json_encode($completedDays),
                    ]);

                    $this->getLogger()->info("账号进入下一天", [
                        'account_id' => $account->id,
                        'current_day' => $nextDay,
                        'plan_id' => $plan->id,
                        'completed_days' => $completedDays
                    ]);
                }
            } else {
                // 未达到目标额度，保持当前状态，等待更多兑换
                $account->update([
                    'status' => ItunesTradeAccount::STATUS_LOCKING,
                    'completed_days' => json_encode($completedDays),
                ]);

                $this->getLogger()->info("当天目标未达成，保持等待状态", [
                    'account_id' => $account->id,
                    'day' => $currentDay,
                    'daily_amount' => $dailyAmount,
                    'daily_target' => $dailyTarget,
                    'remaining_amount' => $dailyTarget - $dailyAmount,
                    'completed_days' => $completedDays
                ]);
            }
        } else {
            $this->getLogger()->info("当天还有待处理任务，暂不更新状态", [
                'account_id' => $account->id,
                'current_day' => $currentDay,
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
}
