<?php

namespace App\Services\Gift;

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeRate;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccountLog;
use App\Services\GiftCardExchangeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GiftCardService
{
    protected GiftCardExchangeService $exchangeService;

    public function __construct(GiftCardExchangeService $exchangeService)
    {
        $this->exchangeService = $exchangeService;
    }

    /**
     * 获取礼品卡兑换专用日志实例
     */
    protected function getLogger()
    {
        return Log::channel('gift_card_exchange');
    }

    /**
     * 兑换礼品卡
     * @throws \Exception
     */
    public function redeem(string $code, string $roomId, string $cardType, string $cardForm, string $batchId): array
    {
        $this->getLogger()->info("开始兑换礼品卡", [
            'code' => $code,
            'room_id' => $roomId,
            'card_type' => $cardType,
            'card_form' => $cardForm,
            'batch_id' => $batchId
        ]);

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
            $account = $this->findAvailableAccount($plan, $roomId);
            $this->getLogger()->info('获取对应的计划', $account->toArray());
            // 5. 执行兑换
            $result = $this->executeRedemption($code, $giftCardInfo, $rate, $plan, $account, $batchId);

            $this->getLogger()->info("礼品卡兑换成功", [
                'code' => $code,
                'result' => $result,
                'batch_id' => $batchId
            ]);

            return $result;

                } catch (\Exception $e) {
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
     * @throws \Exception
     */
    protected function validateGiftCard(string $code): array
    {
        // 调用GiftCardExchangeService的validateGiftCard方法
        $result = $this->exchangeService->validateGiftCard($code);
        $this->getLogger()->info('查卡返回数据：', $result);
        if (!$result['is_valid'] || $result['balance']<=0 ) {
            throw new \Exception("礼品卡无效: " . ($result['message'] ?? '未知错误'));
        }

        return [
            'country_code' => $result['country_code'],
            'amount' => $result['balance'],
            'currency' => $result['currency'] ?? 'USD',
            'valid' => true
        ];
    }

    /**
     * 查找匹配的汇率
     * @throws \Exception
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
//        $groupId = $this->getGroupIdByRoomId($roomId);
//        if ($groupId) {
//            $rateWithGroup = (clone $query)->where('group_id', $groupId)->get();
//
//            if ($rateWithGroup->isNotEmpty()) {
//                $rate = $this->selectBestRateByAmount($rateWithGroup, $amount);
//                if ($rate) {
//                    $this->getLogger()->info("找到匹配群组的汇率", ['rate_id' => $rate->id, 'group_id' => $groupId]);
//                    return $rate;
//                }
//            }
//        }

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

        throw new \Exception("未找到符合条件的汇率: 国家={$countryCode}, 卡类型={$cardType}, 卡形式={$cardForm}, 面额={$amount}");
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
                
                $this->getLogger()->info("固定面额检查", [
                    'fixed_amounts' => $fixedAmounts, 
                    'amount' => $amount,
                    'type' => gettype($fixedAmounts)
                ]);
                
                if (is_array($fixedAmounts) && in_array($amount, $fixedAmounts)) {
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
        // 这里需要根据实际的群组表结构来实现
        // 假设有一个groups表或者room_groups表
        // return DB::table('room_groups')->where('room_id', $roomId)->value('group_id');

        // 临时实现，返回null
        return null;
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
            throw new \Exception("未找到可用的兑换计划: rate_id={$rateId}");
        }

        $this->getLogger()->info("找到可用计划", ['plan_id' => $plan->id, 'rate_id' => $rateId]);

        return $plan;
    }

    /**
     * 查找可用的账号
     */
    protected function findAvailableAccount(ItunesTradePlan $plan, string $roomId): ItunesTradeAccount
    {
        // 1. 优先查找状态为processing的账号
        $query = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE);

        // 如果计划开启了绑定群聊，添加room_id条件
        if ($plan->bind_room && !empty($roomId)) {
            $query->where('room_id', $roomId);
        }

        $account = $query->first();

        if ($account) {
            $this->getLogger()->info("找到processing状态账号", ['account_id' => $account->id]);
            return $account;
        }

        // 2. 查找状态为waiting且天数为1的账号
        $account = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_WAITING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->where('current_plan_day', 1)
            ->orderBy('id', 'asc') // 正序
            ->first();

        if ($account) {
            $this->getLogger()->info("找到waiting状态账号", ['account_id' => $account->id]);
            return $account;
        }

        throw new \Exception("未找到可用的兑换账号");
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

            // 更新账号状态为processing
            $account->update([
                'status' => ItunesTradeAccount::STATUS_PROCESSING,
                'plan_id' => $plan->id,
            ]);

            // 创建兑换日志
            $log = ItunesTradeAccountLog::create([
                'account_id' => $account->id,
                'plan_id' => $plan->id,
                'day' => $account->current_plan_day ?? 1,
                'amount' => $giftCardInfo['amount'],
                'status' => ItunesTradeAccountLog::STATUS_PENDING,
                'gift_card_code' => $code,
                'batch_id' => $batchId,
                'rate_id' => $rate->id,
                'country_code' => $giftCardInfo['country_code'],
                'exchange_time' => now(),
            ]);

            // 触发日志创建事件
            event(new \App\Events\TradeLogCreated($log));

            // 这里应该调用实际的兑换API
            // $exchangeResult = $this->callExchangeApi($account, $code, $giftCardInfo);

            // 模拟兑换结果
            $exchangeResult = [
                'success' => true,
                'transaction_id' => 'TXN_' . time() . '_' . rand(1000, 9999),
                'exchanged_amount' => $giftCardInfo['amount'] * $rate->rate,
                'currency' => 'CNY'
            ];

            if ($exchangeResult['success']) {
                // 更新日志状态为成功
                $log->update([
                    'status' => ItunesTradeAccountLog::STATUS_SUCCESS,
                    'transaction_id' => $exchangeResult['transaction_id'],
                    'exchanged_amount' => $exchangeResult['exchanged_amount'],
                ]);

                // 触发日志更新事件
                event(new \App\Events\TradeLogCreated($log->fresh()));

                // 检查是否完成当天任务
                $this->checkAndUpdateDayCompletion($account, $plan);
            } else {
                // 更新日志状态为失败
                $log->update([
                    'status' => ItunesTradeAccountLog::STATUS_FAILED,
                    'error_message' => $exchangeResult['error'] ?? '兑换失败'
                ]);

                // 触发日志更新事件
                event(new \App\Events\TradeLogCreated($log->fresh()));

                throw new \Exception("兑换API调用失败: " . ($exchangeResult['error'] ?? '未知错误'));
            }

            // 刷新账号信息以获取最新余额
            $account->refresh();

            return [
                'success' => true,
                'log_id' => $log->id,
                'account_id' => $account->id,
                'account_username' => $account->username ?? null,
                'account_balance' => $account->balance ?? 0,
                'plan_id' => $plan->id,
                'rate_id' => $rate->id,
                'country_code' => $giftCardInfo['country_code'],
                'original_amount' => $giftCardInfo['amount'],
                'exchanged_amount' => $exchangeResult['exchanged_amount'],
                'currency' => $exchangeResult['currency'],
                'transaction_id' => $exchangeResult['transaction_id'],
                'exchange_time' => $log->exchange_time->toISOString(),
            ];
        });
    }

    /**
     * 检查并更新天数完成情况
     */
    protected function checkAndUpdateDayCompletion(ItunesTradeAccount $account, ItunesTradePlan $plan): void
    {
        $currentDay = $account->current_plan_day ?? 1;

        // 检查当天是否还有其他待处理的任务
        $pendingCount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('plan_id', $plan->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_PENDING)
            ->count();

        if ($pendingCount == 0) {
            // 当天任务全部完成，检查是否需要进入下一天或完成整个计划
            if ($currentDay >= $plan->plan_days) {
                // 计划完成
                $account->update([
                    'status' => ItunesTradeAccount::STATUS_COMPLETED,
                    'current_plan_day' => null,
                    'plan_id' => null,
                ]);

                $this->getLogger()->info("账号计划完成", ['account_id' => $account->id, 'plan_id' => $plan->id]);
            } else {
                // 进入下一天，但需要等待日期间隔
                $nextDay = $currentDay + 1;
                $account->update([
                    'status' => ItunesTradeAccount::STATUS_WAITING,
                    'current_plan_day' => $nextDay,
                ]);

                $this->getLogger()->info("账号进入下一天", [
                    'account_id' => $account->id,
                    'current_day' => $nextDay,
                    'plan_id' => $plan->id
                ]);
            }
        }
    }

    /**
     * 判断是否为系统错误（需要记录堆栈跟踪）
     */
    protected function isSystemError(\Exception $e): bool
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
     * 检查礼品卡状态（不执行兑换）
     */
    public function check(string $code): array
    {
        try {
            return $this->validateGiftCard($code);
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
