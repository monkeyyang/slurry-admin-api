<?php

namespace App\Services\Gift;

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccountLog;
use App\Models\ItunesTradeRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * 高性能账号查找服务
 *
 * 核心优化策略：
 * 1. 单次SQL查询完成所有过滤和排序
 * 2. 数据库层面的HAVING子句预过滤
 * 3. 最简化的验证逻辑
 * 4. 原子级账号锁定机制
 */
class FindAccountService
{
    /**
     * 获取专用日志实例
     */
    protected function getLogger(): LoggerInterface
    {
        return Log::channel('gift_card_exchange');
    }

    /**
     * 查找可用账号（高性能版本）
     *
     * @param ItunesTradePlan $plan 兑换计划
     * @param string $roomId 房间ID
     * @param array $giftCardInfo 礼品卡信息
     * @param int $currentDay 当前天数（默认为1）
     * @param int $maxRetries 最大重试次数（默认为3）
     * @return ItunesTradeAccount|null 找到的账号或null
     * @throws Exception
     */
    public function findAvailableAccount(
        ItunesTradePlan $plan,
        string $roomId,
        array $giftCardInfo,
        int $currentDay = 1,
        int $maxRetries = 3
    ): ?ItunesTradeAccount {
        $startTime = microtime(true);
        $giftCardAmount = $giftCardInfo['amount'];

        $this->getLogger()->info("开始四层验证账号查找", [
            'plan_id' => $plan->id,
            'room_id' => $roomId,
            'gift_card_amount' => $giftCardAmount,
            'current_day' => $currentDay,
            'validation_layers' => 4,
            'optimization_version' => 'v3.0_four_layer'
        ]);

        try {
            // 重试查找逻辑
            $excludedAccountIds = [];
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                $this->getLogger()->debug("账号查找尝试", [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'excluded_accounts' => count($excludedAccountIds)
                ]);

                // 第1步：执行优化的SQL查询（排除已尝试的账号）
                $accountData = $this->executeOptimizedQuery($plan, $roomId, $giftCardAmount, $currentDay, $excludedAccountIds);

                if (!$accountData) {
                    if ($attempt == 1) {
                        $this->logNoAccountFound($plan, $roomId, $giftCardAmount, $startTime);
                    } else {
                        $this->getLogger()->info("重试查找无更多可用账号", [
                            'attempt' => $attempt,
                            'excluded_count' => count($excludedAccountIds)
                        ]);
                    }
                    return null;
                }

                // 第2步：验证账号约束条件
                if (!$this->validateAccountConstraints($accountData, $plan, $giftCardInfo)) {
                    $this->logConstraintValidationFailed($accountData, $plan, $giftCardInfo, $startTime);
                    $excludedAccountIds[] = $accountData->id;
                    continue; // 尝试下一个账号
                }

                // 第3步：原子锁定账号
                $account = $this->atomicLockAccount($accountData, $plan, $roomId, $currentDay);

                if ($account) {
                    $this->logAccountFound($account, $plan, $giftCardAmount, $startTime, $attempt);
                    return $account;
                } else {
                    // 锁定失败，将此账号加入排除列表，尝试下一个
                    $excludedAccountIds[] = $accountData->id;
                    $this->getLogger()->info("账号锁定失败，尝试下一个账号", [
                        'failed_account_id' => $accountData->id,
                        'attempt' => $attempt,
                        'will_retry' => $attempt < $maxRetries
                    ]);

                    if ($attempt < $maxRetries) {
                        // 短暂延迟后重试
                        usleep(10000); // 10ms
                        continue;
                    }
                }
            }

            // 所有重试都失败了，尝试兜底机制
            $this->getLogger()->warning("所有重试尝试都失败，启动兜底机制", [
                'max_retries' => $maxRetries,
                'excluded_accounts' => count($excludedAccountIds),
                'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);

            // 启动兜底机制：查找金额为0的账号
            return $this->findFallbackAccount($plan, $roomId, $currentDay, $startTime);

        } catch (Exception $e) {
            $endTime = microtime(true);
            $totalTime = ($endTime - $startTime) * 1000;

            $this->getLogger()->error("账号查找过程发生异常", [
                'plan_id' => $plan->id,
                'room_id' => $roomId,
                'gift_card_amount' => $giftCardAmount,
                'error' => $e->getMessage(),
                'execution_time_ms' => round($totalTime, 2),
                'error_type' => get_class($e)
            ]);

            throw $e;
        }
    }



        /**
     * 执行优化的SQL查询（四层验证机制）
     */
    private function executeOptimizedQuery(
        ItunesTradePlan $plan,
        string $roomId,
        float $giftCardAmount,
        int $currentDay,
        array $excludedAccountIds = []
    ): ?object {
        $queryStartTime = microtime(true);

        // 构建高性能SQL查询
        $excludeClause = '';
        if (!empty($excludedAccountIds)) {
            $excludePlaceholders = str_repeat('?,', count($excludedAccountIds) - 1) . '?';
            $excludeClause = " AND a.id NOT IN ($excludePlaceholders)";
        }

        $sql = "
            SELECT a.*,
                   COALESCE(SUM(l.amount), 0) as daily_spent
            FROM itunes_trade_accounts a
            LEFT JOIN itunes_trade_account_logs l ON (
                a.id = l.account_id
                AND l.day = ?
                AND l.status = 'success'
            )
            WHERE a.status = 'processing'
              AND a.login_status = 'valid'
              AND a.amount > 0
              AND a.amount < ?
              AND (a.amount + ?) <= ?
              AND (
                  (a.plan_id = ?) OR
                  (a.room_id = ?) OR
                  (a.plan_id IS NULL)
              )
              $excludeClause
            GROUP BY a.id
            ORDER BY
                CASE
                    WHEN a.plan_id = ? AND a.room_id = ? THEN 1
                    WHEN a.plan_id = ? THEN 2
                    WHEN a.room_id = ? THEN 3
                    WHEN a.plan_id IS NULL THEN 4
                    ELSE 5
                END,
                a.amount DESC,
                a.id ASC
            LIMIT 1
        ";

        $params = [
            $currentDay,                   // l.day = ?
            $plan->total_amount,           // a.amount < ?
            $giftCardAmount,               // (a.amount + ?) <= ?
            $plan->total_amount,           // <= ?
            $plan->id,                     // a.plan_id = ?
            $roomId,                       // a.room_id = ?
        ];

        // 添加排除的账号ID参数
        if (!empty($excludedAccountIds)) {
            $params = array_merge($params, $excludedAccountIds);
        }

        // 添加剩余参数
        $params = array_merge($params, [
            $plan->id,                     // WHEN a.plan_id = ? AND a.room_id = ? THEN 1
            $roomId,                       // AND a.room_id = ?
            $plan->id,                     // WHEN a.plan_id = ? THEN 2
            $roomId                        // WHEN a.room_id = ? THEN 3
        ]);

        $result = DB::select($sql, $params);

        $queryEndTime = microtime(true);
        $queryTime = ($queryEndTime - $queryStartTime) * 1000;

        $this->getLogger()->debug("SQL查询执行完成", [
            'execution_time_ms' => round($queryTime, 2),
            'results_count' => count($result),
            'query_optimization' => 'four_layer_validation_based'
        ]);

        return empty($result) ? null : $result[0];
    }

    /**
     * 验证账号约束条件（三层验证机制）
     */
    private function validateAccountConstraints(
        object $accountData,
        ItunesTradePlan $plan,
        array $giftCardInfo
    ): bool {
        $giftCardAmount = $giftCardInfo['amount'];

        // 第一层：验证礼品卡基本约束
        if (!$this->validateGiftCardConstraints($plan, $giftCardAmount)) {
            return false;
        }

        // 第二层：总额度验证
        if (!$this->validateTotalAmountLimit($accountData, $plan, $giftCardAmount)) {
            return false;
        }

        // 第三层：充满/预留验证（基于计划总额度）
        if (!$this->validateAccountReservation($accountData, $plan, $giftCardAmount)) {
            return false;
        }

        // 第四层：每日计划验证（最后一天可跳过）
        return $this->validateDailyPlanLimit($accountData, $plan, $giftCardAmount);
    }

    /**
     * 验证礼品卡基本约束条件
     */
    private function validateGiftCardConstraints(ItunesTradePlan $plan, float $giftCardAmount): bool
    {
        // 如果没有汇率信息，跳过约束验证
        if (!$plan->rate) {
            return true;
        }

        $rate = $plan->rate;

        // 验证倍数约束
        if ($rate->amount_constraint === ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE) {
            $multipleBase = $rate->multiple_base ?? 0;
            $minAmount = $rate->min_amount ?? 0;

            if ($multipleBase > 0) {
                $isMultiple = ($giftCardAmount % $multipleBase == 0);
                $isAboveMin = ($giftCardAmount >= $minAmount);

                if (!$isMultiple || !$isAboveMin) {
                    $this->getLogger()->debug("倍数约束验证失败", [
                        'gift_card_amount' => $giftCardAmount,
                        'multiple_base' => $multipleBase,
                        'min_amount' => $minAmount,
                        'is_multiple' => $isMultiple,
                        'is_above_min' => $isAboveMin
                    ]);
                    return false;
                }
            }
        }

        // 验证固定面额约束
        elseif ($rate->amount_constraint === ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED) {
            $fixedAmounts = $rate->fixed_amounts ?? [];
            if (is_string($fixedAmounts)) {
                $fixedAmounts = json_decode($fixedAmounts, true) ?: [];
            }

            if (is_array($fixedAmounts) && !empty($fixedAmounts)) {
                $isValidAmount = false;
                foreach ($fixedAmounts as $fixedAmount) {
                    if (abs($giftCardAmount - (float)$fixedAmount) < 0.01) {
                        $isValidAmount = true;
                        break;
                    }
                }

                if (!$isValidAmount) {
                    $this->getLogger()->debug("固定面额约束验证失败", [
                        'gift_card_amount' => $giftCardAmount,
                        'fixed_amounts' => $fixedAmounts
                    ]);
                    return false;
                }
            }
        }

        // 全面额约束或其他情况都通过
        return true;
    }

    /**
     * 验证总额度限制
     */
    private function validateTotalAmountLimit(
        object $accountData,
        ItunesTradePlan $plan,
        float $giftCardAmount
    ): bool {
        $currentBalance = $accountData->amount;
        $totalPlanAmount = $plan->total_amount;
        $afterExchangeBalance = $currentBalance + $giftCardAmount;

        // 检查兑换后余额是否超出计划总额度
        if ($afterExchangeBalance > $totalPlanAmount) {
            $this->getLogger()->info("总额度验证失败", [
                'account_id' => $accountData->id,
                'current_balance' => $currentBalance,
                'gift_card_amount' => $giftCardAmount,
                'after_exchange_balance' => $afterExchangeBalance,
                'total_plan_amount' => $totalPlanAmount,
                'excess_amount' => $afterExchangeBalance - $totalPlanAmount,
                'reason' => '兑换后余额超出计划总额度'
            ]);
            return false;
        }

        $this->getLogger()->debug("总额度验证通过", [
            'account_id' => $accountData->id,
            'current_balance' => $currentBalance,
            'gift_card_amount' => $giftCardAmount,
            'after_exchange_balance' => $afterExchangeBalance,
            'total_plan_amount' => $totalPlanAmount,
            'remaining_amount' => $totalPlanAmount - $afterExchangeBalance
        ]);

        return true;
    }

    /**
     * 验证账号智能预留逻辑（基于计划总额度）
     */
    private function validateAccountReservation(
        object $accountData,
        ItunesTradePlan $plan,
        float $giftCardAmount
    ): bool {
        // 获取账号当前绑定的计划（如果有的话）
        $accountPlanId = $accountData->plan_id;

        // 确定用于预留判断的计划
        $targetPlan = null;
        if ($accountPlanId && $accountPlanId == $plan->id) {
            // 账号已绑定当前计划
            $targetPlan = $plan;
            $this->getLogger()->debug("账号已绑定当前计划", [
                'account_id' => $accountData->id,
                'plan_id' => $plan->id
            ]);
        } elseif ($accountPlanId && $accountPlanId != $plan->id) {
            // 账号绑定了其他计划，需要获取该计划信息
            $targetPlan = ItunesTradePlan::with('rate')->find($accountPlanId);
            $this->getLogger()->debug("账号绑定了其他计划", [
                'account_id' => $accountData->id,
                'account_plan_id' => $accountPlanId,
                'current_plan_id' => $plan->id
            ]);
        } else {
            // 账号未绑定计划，使用当前计划进行判断
            $targetPlan = $plan;
            $this->getLogger()->debug("账号未绑定计划，使用当前计划判断", [
                'account_id' => $accountData->id,
                'target_plan_id' => $plan->id
            ]);
        }

        if (!$targetPlan || !$targetPlan->rate) {
            $this->getLogger()->warning("无法获取目标计划或汇率信息", [
                'account_id' => $accountData->id,
                'target_plan_id' => $targetPlan?->id
            ]);
            return false;
        }

        // 计算账号可用于兑换的余额（基于计划总额度）
        $currentBalance = $accountData->amount;
        $totalPlanAmount = $targetPlan->total_amount;
        $remainingPlanAmount = $totalPlanAmount - $currentBalance;

        $this->getLogger()->debug("计算计划总额度可兑换金额", [
            'account_id' => $accountData->id,
            'current_balance' => $currentBalance,
            'total_plan_amount' => $totalPlanAmount,
            'remaining_plan_amount' => $remainingPlanAmount
        ]);

        // 情况1：检查是否能够充满计划总额度
        if ($giftCardAmount <= $remainingPlanAmount) {
            $this->getLogger()->debug("礼品卡可以充满计划总额度", [
                'account_id' => $accountData->id,
                'gift_card_amount' => $giftCardAmount,
                'remaining_plan_amount' => $remainingPlanAmount,
                'validation_result' => 'can_fill_completely'
            ]);
            return true;
        }

        // 情况2：不能充满，需要检查预留逻辑
        $excessAmount = $giftCardAmount - $remainingPlanAmount;
        $afterExchangeBalance = $currentBalance + $giftCardAmount;

        $this->getLogger()->debug("开始预留逻辑判断", [
            'account_id' => $accountData->id,
            'gift_card_amount' => $giftCardAmount,
            'remaining_plan_amount' => $remainingPlanAmount,
            'excess_amount' => $excessAmount,
            'current_balance' => $currentBalance,
            'after_exchange_balance' => $afterExchangeBalance
        ]);

        // 根据汇率约束类型进行预留判断
        $rate = $targetPlan->rate;
        $constraintType = $rate->amount_constraint;

        switch ($constraintType) {
            case ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE:
                return $this->validateMultipleReservation($accountData, $rate, $excessAmount, $afterExchangeBalance);

            case ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED:
                return $this->validateFixedAmountReservation($accountData, $rate, $excessAmount, $afterExchangeBalance);

            case ItunesTradeRate::AMOUNT_CONSTRAINT_ALL:
                return $this->validateAllAmountReservation($accountData, $excessAmount, $afterExchangeBalance);

            default:
                $this->getLogger()->warning("未知的约束类型", [
                    'account_id' => $accountData->id,
                    'constraint_type' => $constraintType
                ]);
                return false;
        }
    }

    /**
     * 验证倍数约束的预留逻辑
     */
    private function validateMultipleReservation(
        object $accountData,
        $rate,
        float $excessAmount,
        float $afterExchangeBalance
    ): bool {
        $multipleBase = $rate->multiple_base ?? 50; // 默认倍数50
        $minReservation = max(150, $multipleBase); // 最小预留150或倍数基数（取较大值）

        // 检查超出金额是否满足预留要求
        $canReserveMultiple = ($excessAmount >= $minReservation) && ($excessAmount % $multipleBase == 0);

        $this->getLogger()->debug("倍数约束预留验证", [
            'account_id' => $accountData->id,
            'excess_amount' => $excessAmount,
            'multiple_base' => $multipleBase,
            'min_reservation' => $minReservation,
            'can_reserve_multiple' => $canReserveMultiple,
            'is_multiple' => ($excessAmount % $multipleBase == 0),
            'is_above_min' => ($excessAmount >= $minReservation)
        ]);

        if (!$canReserveMultiple) {
            $this->getLogger()->info("倍数约束预留验证失败", [
                'account_id' => $accountData->id,
                'excess_amount' => $excessAmount,
                'required_multiple' => $multipleBase,
                'required_min' => $minReservation,
                'reason' => $excessAmount < $minReservation ? '低于最小预留金额' : '不是倍数的整数倍'
            ]);
            return false;
        }

        return true;
    }

    /**
     * 验证固定面额约束的预留逻辑
     */
    private function validateFixedAmountReservation(
        object $accountData,
        $rate,
        float $excessAmount,
        float $afterExchangeBalance
    ): bool {
        $fixedAmounts = $rate->fixed_amounts ?? [];
        if (is_string($fixedAmounts)) {
            $fixedAmounts = json_decode($fixedAmounts, true) ?: [];
        }

        if (empty($fixedAmounts)) {
            $this->getLogger()->warning("固定面额配置为空", [
                'account_id' => $accountData->id
            ]);
            return false;
        }

        // 找到最小的固定面额作为最小预留
        $minFixedAmount = min($fixedAmounts);

        // 检查超出金额是否匹配任何固定面额
        $matchedAmount = null;
        foreach ($fixedAmounts as $fixedAmount) {
            if (abs($excessAmount - (float)$fixedAmount) < 0.01) {
                $matchedAmount = $fixedAmount;
                break;
            }
        }

        $this->getLogger()->debug("固定面额约束预留验证", [
            'account_id' => $accountData->id,
            'excess_amount' => $excessAmount,
            'fixed_amounts' => $fixedAmounts,
            'min_fixed_amount' => $minFixedAmount,
            'matched_amount' => $matchedAmount
        ]);

        if (!$matchedAmount) {
            $this->getLogger()->info("固定面额约束预留验证失败", [
                'account_id' => $accountData->id,
                'excess_amount' => $excessAmount,
                'available_amounts' => $fixedAmounts,
                'reason' => '超出金额不匹配任何固定面额'
            ]);
            return false;
        }

        // 检查匹配的面额是否满足最小预留要求（通常固定面额50需要预留50）
        if ($matchedAmount < $minFixedAmount) {
            $this->getLogger()->info("固定面额约束预留验证失败", [
                'account_id' => $accountData->id,
                'matched_amount' => $matchedAmount,
                'min_required' => $minFixedAmount,
                'reason' => '匹配面额低于最小预留要求'
            ]);
            return false;
        }

        return true;
    }

    /**
     * 验证全面额约束的预留逻辑
     */
    private function validateAllAmountReservation(
        object $accountData,
        float $excessAmount,
        float $afterExchangeBalance
    ): bool {
        // 全面额约束下，只要有超出金额就可以预留
        $canReserve = $excessAmount > 0;

        $this->getLogger()->debug("全面额约束预留验证", [
            'account_id' => $accountData->id,
            'excess_amount' => $excessAmount,
            'can_reserve' => $canReserve
        ]);

        if (!$canReserve) {
            $this->getLogger()->info("全面额约束预留验证失败", [
                'account_id' => $accountData->id,
                'excess_amount' => $excessAmount,
                'reason' => '无超出金额可预留'
            ]);
        }

        return $canReserve;
    }

    /**
     * 验证每日计划限制（最后一天可跳过）
     */
    private function validateDailyPlanLimit(
        object $accountData,
        ItunesTradePlan $plan,
        float $giftCardAmount
    ): bool {
        // 获取账号当前绑定的计划（如果有的话）
        $accountPlanId = $accountData->plan_id;
        $currentDay = $accountData->current_plan_day ?? 1;

        // 确定用于每日计划验证的计划
        $targetPlan = $plan;
        if (!$accountPlanId) {
            $currentDay = 1; // 未绑定计划时默认为第1天
        }

        // 检查是否为最后一天
        $planDays = $targetPlan->plan_days ?? 1;
        $isLastDay = $currentDay >= $planDays;

        if ($isLastDay) {
            $this->getLogger()->debug("最后一天跳过每日计划验证", [
                'account_id' => $accountData->id,
                'current_day' => $currentDay,
                'plan_days' => $planDays,
                'validation_result' => 'skip_daily_validation'
            ]);
            return true;
        }

        // 计算当天可兑换额度
        $dailyAmounts = $targetPlan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;
        $dailyTarget = $dailyLimit + $targetPlan->float_amount;

        // 获取当天已兑换金额
        $dailySpent = $accountData->daily_spent ?? 0;
        $remainingDailyAmount = $dailyTarget - $dailySpent;

        $this->getLogger()->debug("每日计划验证计算", [
            'account_id' => $accountData->id,
            'current_day' => $currentDay,
            'plan_days' => $planDays,
            'daily_limit' => $dailyLimit,
            'float_amount' => $targetPlan->float_amount,
            'daily_target' => $dailyTarget,
            'daily_spent' => $dailySpent,
            'remaining_daily_amount' => $remainingDailyAmount
        ]);

        // 检查是否超出当日额度
        if ($giftCardAmount > $remainingDailyAmount) {
            $this->getLogger()->info("每日计划验证失败", [
                'account_id' => $accountData->id,
                'gift_card_amount' => $giftCardAmount,
                'remaining_daily_amount' => $remainingDailyAmount,
                'excess_amount' => $giftCardAmount - $remainingDailyAmount,
                'current_day' => $currentDay,
                'reason' => '礼品卡金额超出当日可兑换额度'
            ]);
            return false;
        }

        $this->getLogger()->debug("每日计划验证通过", [
            'account_id' => $accountData->id,
            'gift_card_amount' => $giftCardAmount,
            'remaining_daily_amount' => $remainingDailyAmount,
            'current_day' => $currentDay,
            'validation_result' => 'daily_plan_ok'
        ]);

        return true;
    }

    /**
     * 原子锁定账号
     */
    private function atomicLockAccount(
        object $accountData,
        ItunesTradePlan $plan,
        string $roomId,
        int $currentDay
    ): ?ItunesTradeAccount {
        $lockStartTime = microtime(true);

        // 使用数据库事务确保原子性
        return DB::transaction(function () use ($accountData, $plan, $roomId, $currentDay, $lockStartTime) {
            // 原子更新：只有状态仍为processing时才能锁定
            $lockResult = DB::table('itunes_trade_accounts')
                ->where('id', $accountData->id)
                ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
                ->update([
                    'status' => ItunesTradeAccount::STATUS_LOCKING,
                    'plan_id' => $plan->id,
                    'room_id' => $roomId,
                    'current_plan_day' => $currentDay,
                    'updated_at' => now()
                ]);

            $lockEndTime = microtime(true);
            $lockTime = ($lockEndTime - $lockStartTime) * 1000;

            if ($lockResult > 0) {
                // 锁定成功，获取最新的账号对象
                $account = ItunesTradeAccount::find($accountData->id);

                $this->getLogger()->info("账号原子锁定成功", [
                    'account_id' => $accountData->id,
                    'account_email' => $accountData->account,
                    'lock_time_ms' => round($lockTime, 2),
                    'plan_id' => $plan->id,
                    'room_id' => $roomId,
                    'current_day' => $currentDay
                ]);

                return $account;
            } else {
                $this->getLogger()->warning("账号原子锁定失败", [
                    'account_id' => $accountData->id,
                    'account_email' => $accountData->account,
                    'lock_time_ms' => round($lockTime, 2),
                    'reason' => '账号状态已被其他进程改变'
                ]);

                return null;
            }
        });
    }

    /**
     * 记录找到账号的日志
     */
    private function logAccountFound(
        ItunesTradeAccount $account,
        ItunesTradePlan $plan,
        float $giftCardAmount,
        float $startTime,
        int $attempt = 1
    ): void {
        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;

        $this->getLogger()->info("四层验证账号查找成功", [
            'account_id' => $account->id,
            'account_email' => $account->account,
            'account_balance' => $account->amount,
            'account_current_day' => $account->current_plan_day ?? 1,
            'plan_id' => $plan->id,
            'gift_card_amount' => $giftCardAmount,
            'execution_time_ms' => round($totalTime, 2),
            'performance_level' => $totalTime < 50 ? 'S级(优秀)' : ($totalTime < 200 ? 'A级(良好)' : 'B级(一般)'),
            'validation_layers_passed' => 4,
            'optimization_version' => 'v3.0_four_layer',
            'attempt' => $attempt,
            'retry_enabled' => $attempt > 1 ? 'true' : 'false'
        ]);
    }

    /**
     * 记录未找到账号的日志
     */
    private function logNoAccountFound(
        ItunesTradePlan $plan,
        string $roomId,
        float $giftCardAmount,
        float $startTime
    ): void {
        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;

        $this->getLogger()->warning("未找到符合条件的账号", [
            'plan_id' => $plan->id,
            'room_id' => $roomId,
            'gift_card_amount' => $giftCardAmount,
            'execution_time_ms' => round($totalTime, 2),
            'search_strategy' => 'optimized_single_query'
        ]);
    }

    /**
     * 记录约束验证失败的日志
     */
    private function logConstraintValidationFailed(
        object $accountData,
        ItunesTradePlan $plan,
        array $giftCardInfo,
        float $startTime
    ): void {
        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;

        $this->getLogger()->info("账号约束验证失败", [
            'account_id' => $accountData->id,
            'account_email' => $accountData->account,
            'plan_id' => $plan->id,
            'gift_card_amount' => $giftCardInfo['amount'],
            'constraint_type' => $plan->rate->amount_constraint ?? 'none',
            'execution_time_ms' => round($totalTime, 2)
        ]);
    }

    /**
     * 记录锁定失败的日志
     */
    private function logLockFailed(
        object $accountData,
        ItunesTradePlan $plan,
        float $startTime
    ): void {
        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;

        $this->getLogger()->warning("账号锁定失败", [
            'account_id' => $accountData->id,
            'account_email' => $accountData->account,
            'plan_id' => $plan->id,
            'execution_time_ms' => round($totalTime, 2),
            'reason' => '可能被其他进程抢占'
        ]);
    }

    /**
     * 获取账号查找统计信息（用于监控和调试）
     */
    public function getSearchStatistics(ItunesTradePlan $plan, string $roomId): array
    {
        $stats = [];

        // 统计各状态的账号数量
        $statusCounts = DB::table('itunes_trade_accounts')
            ->select('status', DB::raw('count(*) as count'))
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        // 统计计划绑定情况
        $planBindingCounts = [
            'current_plan' => DB::table('itunes_trade_accounts')
                ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
                ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
                ->where('plan_id', $plan->id)
                ->count(),
            'current_room' => DB::table('itunes_trade_accounts')
                ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
                ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
                ->where('room_id', $roomId)
                ->count(),
            'unbound' => DB::table('itunes_trade_accounts')
                ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
                ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
                ->whereNull('plan_id')
                ->count()
        ];

        return [
            'status_distribution' => $statusCounts,
            'plan_binding_distribution' => $planBindingCounts,
            'total_processing' => $statusCounts[ItunesTradeAccount::STATUS_PROCESSING] ?? 0,
            'total_available' => array_sum($planBindingCounts),
            'timestamp' => now()->toISOString()
        ];
    }

    /**
     * 兜底机制：查找金额为0的账号
     */
    private function findFallbackAccount(
        ItunesTradePlan $plan,
        string $roomId,
        int $currentDay,
        float $startTime
    ): ?ItunesTradeAccount {
        $fallbackStartTime = microtime(true);

        $this->getLogger()->info("启动兜底机制查找", [
            'plan_id' => $plan->id,
            'room_id' => $roomId,
            'current_day' => $currentDay,
            'mechanism' => 'zero_amount_fallback'
        ]);

        // 查找金额为0的账号
        $fallbackAccountData = $this->executeFallbackQuery($plan, $roomId);

        if (!$fallbackAccountData) {
            $this->logNoFallbackAccountFound($plan, $roomId, $startTime);
            return null;
        }

        $this->getLogger()->info("找到兜底账号", [
            'account_id' => $fallbackAccountData->id,
            'account_email' => $fallbackAccountData->account,
            'current_plan_id' => $fallbackAccountData->plan_id,
            'current_room_id' => $fallbackAccountData->room_id,
            'target_plan_id' => $plan->id,
            'target_room_id' => $roomId
        ]);

        // 原子锁定并更新兜底账号
        $account = $this->atomicLockAndUpdateFallbackAccount(
            $fallbackAccountData,
            $plan,
            $roomId,
            $currentDay
        );

        if ($account) {
            $fallbackEndTime = microtime(true);
            $fallbackTime = ($fallbackEndTime - $fallbackStartTime) * 1000;
            $totalTime = ($fallbackEndTime - $startTime) * 1000;

            $this->getLogger()->info("兜底机制成功", [
                'account_id' => $account->id,
                'account_email' => $account->account,
                'fallback_time_ms' => round($fallbackTime, 2),
                'total_time_ms' => round($totalTime, 2),
                'plan_updated' => $fallbackAccountData->plan_id != $plan->id,
                'room_updated' => $fallbackAccountData->room_id != $roomId
            ]);

            return $account;
        }

        $this->getLogger()->warning("兜底账号锁定失败", [
            'account_id' => $fallbackAccountData->id,
            'fallback_time_ms' => round((microtime(true) - $fallbackStartTime) * 1000, 2)
        ]);

        return null;
    }

    /**
     * 执行兜底查询：查找金额为0的账号
     */
    private function executeFallbackQuery(
        ItunesTradePlan $plan,
        string $roomId
    ): ?object {
        $queryStartTime = microtime(true);

        $sql = "
            SELECT a.*
            FROM itunes_trade_accounts a
            WHERE a.status = 'processing'
              AND a.login_status = 'valid'
              AND a.amount = 0
            ORDER BY
                CASE
                    WHEN a.plan_id = ? AND a.room_id = ? THEN 1
                    WHEN a.plan_id = ? THEN 2
                    WHEN a.room_id = ? THEN 3
                    WHEN a.plan_id IS NULL THEN 4
                    ELSE 5
                END,
                a.id ASC
            LIMIT 1
        ";

        $params = [
            $plan->id,  // WHEN a.plan_id = ? AND a.room_id = ? THEN 1
            $roomId,    // AND a.room_id = ?
            $plan->id,  // WHEN a.plan_id = ? THEN 2
            $roomId     // WHEN a.room_id = ? THEN 3
        ];

        $result = DB::select($sql, $params);

        $queryEndTime = microtime(true);
        $queryTime = ($queryEndTime - $queryStartTime) * 1000;

        $this->getLogger()->debug("兜底查询执行完成", [
            'execution_time_ms' => round($queryTime, 2),
            'results_count' => count($result),
            'query_type' => 'zero_amount_fallback'
        ]);

        return empty($result) ? null : $result[0];
    }

    /**
     * 原子锁定并更新兜底账号
     */
    private function atomicLockAndUpdateFallbackAccount(
        object $fallbackAccountData,
        ItunesTradePlan $plan,
        string $roomId,
        int $currentDay
    ): ?ItunesTradeAccount {
        $lockStartTime = microtime(true);

        // 使用数据库事务确保原子性
        return DB::transaction(function () use ($fallbackAccountData, $plan, $roomId, $currentDay, $lockStartTime) {
            // 检查是否需要更新计划和房间绑定
            $needsPlanUpdate = $fallbackAccountData->plan_id && $fallbackAccountData->plan_id != $plan->id;
            $needsRoomUpdate = $fallbackAccountData->room_id && $fallbackAccountData->room_id != $roomId;

            $updateData = [
                'status' => ItunesTradeAccount::STATUS_LOCKING,
                'plan_id' => $plan->id,
                'room_id' => $roomId,
                'current_plan_day' => $currentDay,
                'updated_at' => now()
            ];

            // 原子更新：只有状态仍为processing时才能锁定
            $lockResult = DB::table('itunes_trade_accounts')
                ->where('id', $fallbackAccountData->id)
                ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
                ->where('amount', 0) // 确保仍然是0金额
                ->update($updateData);

            $lockEndTime = microtime(true);
            $lockTime = ($lockEndTime - $lockStartTime) * 1000;

            if ($lockResult > 0) {
                // 锁定成功，获取最新的账号对象
                $account = ItunesTradeAccount::find($fallbackAccountData->id);

                $this->getLogger()->info("兜底账号原子锁定成功", [
                    'account_id' => $fallbackAccountData->id,
                    'account_email' => $fallbackAccountData->account,
                    'lock_time_ms' => round($lockTime, 2),
                    'plan_id' => $plan->id,
                    'room_id' => $roomId,
                    'current_day' => $currentDay,
                    'plan_updated' => $needsPlanUpdate,
                    'room_updated' => $needsRoomUpdate,
                    'original_plan_id' => $fallbackAccountData->plan_id,
                    'original_room_id' => $fallbackAccountData->room_id
                ]);

                return $account;
            } else {
                $this->getLogger()->warning("兜底账号原子锁定失败", [
                    'account_id' => $fallbackAccountData->id,
                    'account_email' => $fallbackAccountData->account,
                    'lock_time_ms' => round($lockTime, 2),
                    'reason' => '账号状态已被其他进程改变或金额不为0'
                ]);

                return null;
            }
        });
    }

    /**
     * 记录未找到兜底账号的日志
     */
    private function logNoFallbackAccountFound(
        ItunesTradePlan $plan,
        string $roomId,
        float $startTime
    ): void {
        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;

        $this->getLogger()->warning("兜底机制失败：未找到金额为0的账号", [
            'plan_id' => $plan->id,
            'room_id' => $roomId,
            'total_time_ms' => round($totalTime, 2),
            'fallback_strategy' => 'zero_amount_accounts',
            'recommendation' => '考虑增加更多金额为0的备用账号'
        ]);
    }
}
