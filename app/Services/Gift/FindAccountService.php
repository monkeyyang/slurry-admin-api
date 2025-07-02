<?php

namespace App\Services\Gift;

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * 高性能账号筛选服务（交集筛选版）
 *
 * 核心策略：
 * 1. 根据各个条件分别筛选账号集合
 * 2. 计算多个集合的交集
 * 3. 对交集结果进行优先级排序
 * 4. 选出最优账号并原子锁定
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
     * 查找最优账号（交集筛选，高性能版本）
     *
     * @param ItunesTradePlan $plan 兑换计划
     * @param string $roomId 房间ID
     * @param array $giftCardInfo 礼品卡信息
     * @param int $currentDay 当前天数（默认为1）
     * @param bool $testMode 测试模式，不执行真正的锁定（默认为false）
     * @return ItunesTradeAccount|null 找到的最优账号或null
     * @throws Exception
     */
    public function findOptimalAccount(
        ItunesTradePlan $plan,
        string          $roomId,
        array           $giftCardInfo,
        int             $currentDay = 1,
        bool            $testMode = false
    ): ?ItunesTradeAccount
    {
        $startTime       = microtime(true);
        $giftCardAmount  = $giftCardInfo['amount'];
        $giftCardCountry = $giftCardInfo['country'] ?? $plan->country;

        $this->getLogger()->info("开始交集筛选账号", [
            'plan_id'           => $plan->id,
            'room_id'           => $roomId,
            'gift_card_amount'  => $giftCardAmount,
            'gift_card_country' => $giftCardCountry,
            'current_day'       => $currentDay,
            'bind_room'         => $plan->bind_room ?? false,
            'mode'              => 'intersection_filtering'
        ]);

        try {
            // 1. 基础条件筛选（SQL层面）
            $baseAccountIds = $this->getBaseQualifiedAccountIds($plan, $giftCardAmount, $giftCardCountry);

            if (empty($baseAccountIds)) {
                $this->logNoAccountFound($plan, $roomId, $giftCardAmount, $startTime, 'base_qualification');
                return null;
            }

            $this->getLogger()->debug("基础条件筛选完成", [
                'qualified_count' => count($baseAccountIds),
                'stage'           => 'base_qualification'
            ]);

            // 2. 礼品卡约束筛选
            $constraintAccountIds = $this->getConstraintQualifiedAccountIds($baseAccountIds, $plan, $giftCardAmount);

            if (empty($constraintAccountIds)) {
                $this->logNoAccountFound($plan, $roomId, $giftCardAmount, $startTime, 'constraint_qualification');
                return null;
            }

            $this->getLogger()->debug("约束条件筛选完成", [
                'qualified_count' => count($constraintAccountIds),
                'stage'           => 'constraint_qualification'
            ]);

            // 3. 群聊绑定筛选
            $roomBindingAccountIds = $this->getRoomBindingQualifiedAccountIds($constraintAccountIds, $plan, $giftCardInfo);

            if (empty($roomBindingAccountIds)) {
                $this->logNoAccountFound($plan, $roomId, $giftCardAmount, $startTime, 'room_binding_qualification');
                return null;
            }

            $this->getLogger()->debug("群聊绑定筛选完成", [
                'qualified_count' => count($roomBindingAccountIds),
                'stage'           => 'room_binding_qualification'
            ]);

            // 4. 容量检查筛选（充满/预留逻辑）
            $capacityAccountIds = $this->getCapacityQualifiedAccountIds($roomBindingAccountIds, $plan, $giftCardAmount);

            if (empty($capacityAccountIds)) {
                $this->logNoAccountFound($plan, $roomId, $giftCardAmount, $startTime, 'capacity_qualification');
                return null;
            }

            $this->getLogger()->debug("容量检查筛选完成", [
                'qualified_count' => count($capacityAccountIds),
                'stage'           => 'capacity_qualification'
            ]);

            // 5. 每日计划筛选
            $dailyPlanAccountIds = $this->getDailyPlanQualifiedAccountIds($capacityAccountIds, $plan, $giftCardAmount, $currentDay);

            if (empty($dailyPlanAccountIds)) {
                $this->logNoAccountFound($plan, $roomId, $giftCardAmount, $startTime, 'daily_plan_qualification');
                return null;
            }

            $this->getLogger()->debug("每日计划筛选完成", [
                'qualified_count' => count($dailyPlanAccountIds),
                'stage'           => 'daily_plan_qualification'
            ]);

            // 6. 获取最终候选账号并排序
            $optimalAccount = $this->selectOptimalAccount($dailyPlanAccountIds, $plan, $roomId, $currentDay, $giftCardAmount, $testMode);

            if ($optimalAccount) {
                $this->logOptimalAccountFound($optimalAccount, $plan, $giftCardAmount, $startTime, $testMode);
                return $optimalAccount;
            }

            // 7. 兜底机制：查找金额为0的账号
            $fallbackAccount = $this->findFallbackAccount($plan, $roomId, $giftCardCountry);

            if ($fallbackAccount) {
                $this->getLogger()->info("使用兜底账号", [
                    'account_id'    => $fallbackAccount->id,
                    'account_email' => $fallbackAccount->account,
                    'plan_id'       => $plan->id
                ]);
                return $fallbackAccount;
            }

            $this->logNoAccountFound($plan, $roomId, $giftCardAmount, $startTime, 'final_selection');
            return null;

        } catch (Exception $e) {
            $endTime   = microtime(true);
            $totalTime = ($endTime - $startTime) * 1000;

            $this->getLogger()->error("账号筛选过程发生异常", [
                'plan_id'           => $plan->id,
                'room_id'           => $roomId,
                'gift_card_amount'  => $giftCardAmount,
                'error'             => $e->getMessage(),
                'execution_time_ms' => round($totalTime, 2),
                'error_type'        => get_class($e)
            ]);

            throw $e;
        }
    }

    /**
     * 第1层：获取基础条件合格的账号ID列表
     */
    private function getBaseQualifiedAccountIds(
        ItunesTradePlan $plan,
        float           $giftCardAmount,
        string          $country
    ): array
    {
        $sql = "
            SELECT a.id
            FROM itunes_trade_accounts a
            WHERE a.status = 'processing'
              AND a.login_status = 'valid'
              AND a.country_code = ?
              AND a.amount >= 0
              AND (a.amount + ?) <= ?
              AND a.deleted_at IS NULL
        ";

        $params = [
            $country,
            $giftCardAmount,
            $plan->total_amount
        ];

        $result = DB::select($sql, $params);
        return array_column($result, 'id');
    }

    /**
     * 第2层：礼品卡约束条件筛选
     */
    private function getConstraintQualifiedAccountIds(
        array           $accountIds,
        ItunesTradePlan $plan,
        float           $giftCardAmount
    ): array
    {
        if (empty($accountIds)) {
            return [];
        }

        // 如果没有汇率信息，跳过约束验证
        if (!$plan->rate) {
            return $accountIds;
        }

        $rate           = $plan->rate;
        $constraintType = $rate->amount_constraint;

        // 验证倍数约束
        if ($constraintType === ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE) {
            $multipleBase = $rate->multiple_base ?? 0;
            $minAmount    = $rate->min_amount ?? 0;
            $maxAmount    = $rate->max_amount ?? 500;

            if ($multipleBase > 0) {
                $isMultiple = ($giftCardAmount % $multipleBase == 0);
                $isAboveMin = ($giftCardAmount >= $minAmount);
                $isBelowMax = ($giftCardAmount <= $maxAmount);

                if (!$isMultiple || !$isAboveMin || !$isBelowMax) {
                    $this->getLogger()->debug("倍数约束验证失败", [
                        'gift_card_amount' => $giftCardAmount,
                        'multiple_base'    => $multipleBase,
                        'min_amount'       => $minAmount,
                        'max_amount'       => $maxAmount,
                        'is_multiple'      => $isMultiple,
                        'is_above_min'     => $isAboveMin,
                        'is_below_max'     => $isBelowMax
                    ]);
                    return [];
                }
            }
        } // 验证固定面额约束
        elseif ($constraintType === ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED) {
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
                        'fixed_amounts'    => $fixedAmounts
                    ]);
                    return [];
                }
            }
        }

        // 全面额约束或其他情况都通过
        return $accountIds;
    }

    /**
     * 第3层：群聊绑定逻辑筛选
     */
    private function getRoomBindingQualifiedAccountIds(
        array           $accountIds,
        ItunesTradePlan $plan,
        array           $giftCardInfo
    ): array
    {
        if (empty($accountIds)) {
            return [];
        }

        $bindRoom = $plan->bind_room ?? false;
        $roomId   = $giftCardInfo['room_id'] ?? '';

        // 如果不需要绑定群聊，所有账号都通过
        if (!$bindRoom) {
            return $accountIds;
        }

        // 需要绑定群聊但没有提供room_id
        if (empty($roomId)) {
            $this->getLogger()->debug("群聊绑定验证失败：礼品卡信息中缺少room_id", [
                'plan_id'       => $plan->id,
                'bind_room'     => true,
                'account_count' => count($accountIds)
            ]);
            return [];
        }

        // 查询可以绑定该群聊的账号
        $placeholders = str_repeat('?,', count($accountIds) - 1) . '?';
        $sql          = "
            SELECT id
            FROM itunes_trade_accounts
            WHERE id IN ($placeholders)
              AND (room_id IS NULL OR room_id = ?)
              AND deleted_at IS NULL
        ";

        $params = array_merge($accountIds, [$roomId]);
        $result = DB::select($sql, $params);

        return array_column($result, 'id');
    }

    /**
     * 第4层：容量检查筛选（充满/预留逻辑）
     */
    private function getCapacityQualifiedAccountIds(
        array           $accountIds,
        ItunesTradePlan $plan,
        float           $giftCardAmount
    ): array
    {
        if (empty($accountIds)) {
            return [];
        }

        $qualifiedIds = [];
        $placeholders = str_repeat('?,', count($accountIds) - 1) . '?';

        // 批量获取账号信息
        $accounts = DB::select("
            SELECT id, amount
            FROM itunes_trade_accounts
            WHERE id IN ($placeholders)
              AND deleted_at IS NULL
        ", $accountIds);

        foreach ($accounts as $accountData) {
            if ($this->validateAccountCapacity($accountData, $plan, $giftCardAmount)) {
                $qualifiedIds[] = $accountData->id;
            }
        }

        return $qualifiedIds;
    }

    /**
     * 验证单个账号的容量（充满/预留逻辑）
     */
    private function validateAccountCapacity(
        object          $accountData,
        ItunesTradePlan $plan,
        float           $giftCardAmount
    ): bool
    {
        $currentBalance      = $accountData->amount;
        $totalPlanAmount     = $plan->total_amount;
        $afterExchangeAmount = $currentBalance + $giftCardAmount;

        // 情况1：正好充满计划总额度
        if (abs($afterExchangeAmount - $totalPlanAmount) < 0.01) {
            return true;
        }

        // 情况2：不能充满，检查剩余空间是否符合预留约束
        // B = 计划总额 - 账户余额 - 礼品卡面额
        $remainingSpace = $totalPlanAmount - $currentBalance - $giftCardAmount;

        // 如果没有汇率信息，允许任何剩余空间
        if (!$plan->rate) {
            return true;
        }

        $rate           = $plan->rate;
        $constraintType = $rate->amount_constraint;

        switch ($constraintType) {
            case ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE:
                $multipleBase = $rate->multiple_base ?? 50;
                $minAmount    = $rate->min_amount ?? 150;

                // A = max(倍数基数, 最小值)
                $A = max($multipleBase, $minAmount);

                // 条件：B >= A 且 B % 倍数基数 == 0
                return ($remainingSpace >= $A) && ($remainingSpace % $multipleBase == 0);

            case ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED:
                $fixedAmounts = $rate->fixed_amounts ?? [];
                if (is_string($fixedAmounts)) {
                    $fixedAmounts = json_decode($fixedAmounts, true) ?: [];
                }

                if (empty($fixedAmounts)) {
                    return false;
                }

                // 检查剩余空间是否能容纳至少一张最小面额的礼品卡
                $minFixedAmount = min($fixedAmounts);
                return ($remainingSpace - $minFixedAmount) >= 0;

            case ItunesTradeRate::AMOUNT_CONSTRAINT_ALL:
                return true; // 全面额约束：任何剩余空间都可以

            default:
                return true; // 未知约束类型，允许
        }
    }

    /**
     * 第5层：每日计划限制筛选（优化版 - 批量查询）
     */
    private function getDailyPlanQualifiedAccountIds(
        array           $accountIds,
        ItunesTradePlan $plan,
        float           $giftCardAmount,
        int             $currentDay
    ): array
    {
        if (empty($accountIds)) {
            return [];
        }

        $qualifiedIds = [];
        $placeholders = str_repeat('?,', count($accountIds) - 1) . '?';

        // 批量获取账号基本信息
        $sql = "
            SELECT a.id, a.plan_id, a.current_plan_day
            FROM itunes_trade_accounts a
            WHERE a.id IN ($placeholders)
              AND a.deleted_at IS NULL
        ";

        $accounts = DB::select($sql, $accountIds);

        if (empty($accounts)) {
            return [];
        }

        // 批量查询所有账号的已兑换金额
        $dailySpentMap = $this->batchGetAccountsDailySpent($accounts, $currentDay);

        // 验证每个账号的每日限制
        foreach ($accounts as $accountData) {
            if ($this->validateDailyPlanLimitOptimized($accountData, $plan, $giftCardAmount, $currentDay, $dailySpentMap)) {
                $qualifiedIds[] = $accountData->id;
            }
        }

        return $qualifiedIds;
    }

    /**
     * 验证每日计划限制（修正版，根据账号实际天数查询已兑换金额）
     */
    private function validateDailyPlanLimitWithCorrectDay(
        object          $accountData,
        ItunesTradePlan $plan,
        float           $giftCardAmount,
        int             $currentDay
    ): bool
    {
        $accountPlanId     = $accountData->plan_id;
        $accountCurrentDay = $accountData->current_plan_day ?? 1;

        // 确定用于验证的天数
        $validationDay = $accountPlanId ? $accountCurrentDay : $currentDay;

        // 检查是否为最后一天
        $planDays  = $plan->plan_days ?? 1;
        $isLastDay = $validationDay >= $planDays;

        if ($isLastDay) {
            return true; // 最后一天跳过每日计划验证
        }

        // 计算当天限额
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit   = $dailyAmounts[$validationDay - 1] ?? 0;
        $dailyTarget  = $dailyLimit + $plan->float_amount;

        // 根据实际验证天数查询该账号当天的已兑换金额
        $dailySpent = $this->getAccountDailySpent($accountData->id, $validationDay);

        $remainingDailyAmount = $dailyTarget - $dailySpent;

        return $giftCardAmount <= $remainingDailyAmount;
    }

    /**
     * 批量获取多个账号在各自天数的已兑换金额（性能优化版）
     */
    private function batchGetAccountsDailySpent(array $accounts, int $currentDay): array
    {
        if (empty($accounts)) {
            return [];
        }

        $dailySpentMap = [];

        // 按天数分组账号，减少查询次数
        $accountsByDay = [];
        foreach ($accounts as $accountData) {
            $accountPlanId = $accountData->plan_id;
            $accountCurrentDay = $accountData->current_plan_day ?? 1;
            $validationDay = $accountPlanId ? $accountCurrentDay : $currentDay;

            $accountsByDay[$validationDay][] = $accountData->id;
        }

        // 分组批量查询
        foreach ($accountsByDay as $day => $accountIds) {
            if (empty($accountIds)) continue;

            $placeholders = str_repeat('?,', count($accountIds) - 1) . '?';
            $params = array_merge($accountIds, [$day]);

            $results = DB::select("
                SELECT account_id, COALESCE(SUM(amount), 0) as daily_spent
                FROM itunes_trade_account_logs
                WHERE account_id IN ($placeholders)
                  AND day = ?
                  AND status = 'success'
                GROUP BY account_id
            ", $params);

            // 建立映射关系
            foreach ($results as $result) {
                $dailySpentMap[$result->account_id] = $result->daily_spent;
            }

            // 为没有记录的账号设置0
            foreach ($accountIds as $accountId) {
                if (!isset($dailySpentMap[$accountId])) {
                    $dailySpentMap[$accountId] = 0;
                }
            }
        }

        return $dailySpentMap;
    }

    /**
     * 优化后的每日计划限制验证（使用预查询的daily spent）
     */
    private function validateDailyPlanLimitOptimized(
        object $accountData,
        ItunesTradePlan $plan,
        float $giftCardAmount,
        int $currentDay,
        array $dailySpentMap
    ): bool
    {
        $accountPlanId = $accountData->plan_id;
        $accountCurrentDay = $accountData->current_plan_day ?? 1;

        // 确定用于验证的天数
        $validationDay = $accountPlanId ? $accountCurrentDay : $currentDay;

        // 检查是否为最后一天
        $planDays = $plan->plan_days ?? 1;
        $isLastDay = $validationDay >= $planDays;

        if ($isLastDay) {
            return true; // 最后一天跳过每日计划验证
        }

        // 计算当天限额
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$validationDay - 1] ?? 0;
        $dailyTarget = $dailyLimit + $plan->float_amount;

        // 从预查询结果获取已兑换金额
        $dailySpent = $dailySpentMap[$accountData->id] ?? 0;

        $remainingDailyAmount = $dailyTarget - $dailySpent;

        return $giftCardAmount <= $remainingDailyAmount;
    }

    /**
     * 获取指定账号在指定天数的已兑换金额（保留向后兼容）
     * @deprecated 使用 batchGetAccountsDailySpent 替代
     */
    private function getAccountDailySpent(int $accountId, int $day): float
    {
        $result = DB::select("
            SELECT COALESCE(SUM(amount), 0) as daily_spent
            FROM itunes_trade_account_logs
            WHERE account_id = ?
              AND day = ?
              AND status = 'success'
        ", [$accountId, $day]);

        return $result[0]->daily_spent ?? 0;
    }

    /**
     * 验证每日计划限制（已废弃，保留向后兼容）
     * @deprecated 使用 validateDailyPlanLimitWithCorrectDay 替代
     */
    private function validateDailyPlanLimit(
        object          $accountData,
        ItunesTradePlan $plan,
        float           $giftCardAmount,
        int             $currentDay
    ): bool
    {
        // 向后兼容，直接调用新方法
        return $this->validateDailyPlanLimitWithCorrectDay($accountData, $plan, $giftCardAmount, $currentDay);
    }

    /**
     * 第6层：从最终候选中选择最优账号并原子锁定
     */
    private function selectOptimalAccount(
        array           $accountIds,
        ItunesTradePlan $plan,
        string          $roomId,
        int             $currentDay,
        float           $giftCardAmount,
        bool            $testMode = false
    ): ?ItunesTradeAccount
    {
        if (empty($accountIds)) {
            return null;
        }

        // 按优先级排序获取最优账号
        $optimalAccountIds = $this->sortAccountsByPriority($accountIds, $plan, $roomId, $giftCardAmount);
        if ($testMode) {
            // 测试模式：只返回最优账号信息，不执行锁定
            if (!empty($optimalAccountIds)) {
                $bestAccountId = $optimalAccountIds[0];
                $account = ItunesTradeAccount::find($bestAccountId);

                $this->getLogger()->info("测试模式找到最优账号", [
                    'account_id'    => $bestAccountId,
                    'account_email' => $account->account ?? 'unknown',
                    'plan_id'       => $plan->id,
                    'test_mode'     => true,
                    'no_locking'    => true
                ]);

                return $account;
            }
            return null;
        }

        // 生产模式：尝试锁定排序后的账号（从最优开始）
        foreach ($optimalAccountIds as $accountId) {
            $account = $this->attemptLockAccount($accountId, $plan, $roomId, $currentDay);
            if ($account) {
                return $account;
            }
        }

        return null;
    }

    /**
     * 按优先级排序账号
     */
    private function sortAccountsByPriority(
        array           $accountIds,
        ItunesTradePlan $plan,
        string          $roomId,
        float           $giftCardAmount
    ): array
    {
        if (empty($accountIds)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($accountIds) - 1) . '?';

        $sql = "
            SELECT a.*,
                   CASE
                       WHEN a.plan_id = ? AND a.room_id = ? THEN 1
                       WHEN a.plan_id = ? THEN 2
                       WHEN a.room_id = ? THEN 3
                       WHEN a.plan_id IS NULL THEN 4
                       ELSE 5
                   END as binding_priority,
                   CASE
                       WHEN (a.amount + ?) = ? THEN 3
                       WHEN (a.amount + ?) < ? THEN 2
                       ELSE 1
                   END as capacity_priority
            FROM itunes_trade_accounts a
            WHERE a.id IN ($placeholders)
              AND a.deleted_at IS NULL
            ORDER BY
                binding_priority ASC,
                capacity_priority DESC,
                a.amount DESC,
                a.id ASC
        ";

        $params = [
            $plan->id,          // WHEN a.plan_id = ? AND a.room_id = ? THEN 1
            $roomId,            // AND a.room_id = ?
            $plan->id,          // WHEN a.plan_id = ? THEN 2
            $roomId,            // WHEN a.room_id = ? THEN 3
            $giftCardAmount,    // WHEN (a.amount + ?) = ? THEN 3
            $plan->total_amount,// = ?
            $giftCardAmount,    // WHEN (a.amount + ?) < ? THEN 2
            $plan->total_amount // < ?
        ];

        $params = array_merge($params, $accountIds);

        $result = DB::select($sql, $params);
        return array_column($result, 'id');
    }

    /**
     * 尝试原子锁定账号
     */
    private function attemptLockAccount(
        int             $accountId,
        ItunesTradePlan $plan,
        string          $roomId,
        int             $currentDay
    ): ?ItunesTradeAccount
    {
        return DB::transaction(function () use ($accountId, $plan, $roomId, $currentDay) {
            // 原子更新：只有状态仍为processing时才能锁定
            $lockResult = DB::table('itunes_trade_accounts')
                ->where('id', $accountId)
                ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
                ->whereNull('deleted_at')
                ->update([
                    'status'           => ItunesTradeAccount::STATUS_LOCKING,
                    'plan_id'          => $plan->id,
                    'room_id'          => $roomId,
                    'current_plan_day' => $currentDay,
                    'updated_at'       => now()
                ]);

            if ($lockResult > 0) {
                // 锁定成功，获取最新的账号对象
                $account = ItunesTradeAccount::find($accountId);

                $this->getLogger()->info("账号原子锁定成功", [
                    'account_id'    => $accountId,
                    'account_email' => $account->account ?? 'unknown',
                    'plan_id'       => $plan->id,
                    'room_id'       => $roomId,
                    'current_day'   => $currentDay,
                    'lock_method'   => 'intersection_filtering'
                ]);

                return $account;
            }

            return null; // 锁定失败
        });
    }

    /**
     * 查找兜底账号（金额为0的账号）
     */
    private function findFallbackAccount(
        ItunesTradePlan $plan,
        string          $roomId,
        string          $country
    ): ?ItunesTradeAccount
    {
        $this->getLogger()->debug("查找兜底账号", [
            'plan_id' => $plan->id,
            'room_id' => $roomId,
            'country' => $country
        ]);

        $sql = "
            SELECT a.*
            FROM itunes_trade_accounts a
            WHERE a.status = 'processing'
              AND a.login_status = 'valid'
              AND a.country_code = ?
              AND a.amount = 0
              AND a.deleted_at IS NULL
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

        $params = [$country, $plan->id, $roomId, $plan->id, $roomId];
        $result = DB::select($sql, $params);

        if (empty($result)) {
            return null;
        }

        return ItunesTradeAccount::find($result[0]->id);
    }

    /**
     * 记录找到最优账号的日志
     */
    private function logOptimalAccountFound(
        ItunesTradeAccount $account,
        ItunesTradePlan    $plan,
        float              $giftCardAmount,
        float              $startTime,
        bool               $testMode = false
    ): void
    {
        $endTime   = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;

        $this->getLogger()->info("交集筛选找到最优账号", [
            'account_id'          => $account->id,
            'account_email'       => $account->account,
            'account_balance'     => $account->amount,
            'account_current_day' => $account->current_plan_day ?? 1,
            'plan_id'             => $plan->id,
            'gift_card_amount'    => $giftCardAmount,
            'execution_time_ms'   => round($totalTime, 2),
            'performance_level'   => $totalTime < 30 ? 'S级' : ($totalTime < 100 ? 'A级' : 'B级'),
            'filtering_method'    => 'intersection_filtering',
            'test_mode'           => $testMode,
            'account_locked'      => !$testMode
        ]);
    }

    /**
     * 记录未找到账号的日志
     */
    private function logNoAccountFound(
        ItunesTradePlan $plan,
        string          $roomId,
        float           $giftCardAmount,
        float           $startTime,
        string          $stage = 'unknown'
    ): void
    {
        $endTime   = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;

        $this->getLogger()->warning("交集筛选未找到符合条件的账号", [
            'plan_id'           => $plan->id,
            'room_id'           => $roomId,
            'gift_card_amount'  => $giftCardAmount,
            'execution_time_ms' => round($totalTime, 2),
            'failed_stage'      => $stage,
            'filtering_method'  => 'intersection_filtering'
        ]);
    }

    /**
     * 获取账号筛选统计信息
     */
    public function getSelectionStatistics(string $country, ItunesTradePlan $plan = null): array
    {
        $stats = [];

        // 按国家统计账号状态分布
        $query = DB::table('itunes_trade_accounts')
            ->where('country_code', $country)
            ->whereNull('deleted_at');

        $statusCounts = (clone $query)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        $loginStatusCounts = (clone $query)
            ->select('login_status', DB::raw('count(*) as count'))
            ->groupBy('login_status')
            ->get()
            ->pluck('count', 'login_status')
            ->toArray();

        $stats['country']                   = $country;
        $stats['status_distribution']       = $statusCounts;
        $stats['login_status_distribution'] = $loginStatusCounts;
        $stats['total_processing']          = $statusCounts[ItunesTradeAccount::STATUS_PROCESSING] ?? 0;
        $stats['total_active_login']        = $loginStatusCounts[ItunesTradeAccount::STATUS_LOGIN_ACTIVE] ?? 0;

        // 如果指定了计划，统计计划相关信息
        if ($plan) {
            $planStats = (clone $query)
                ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
                ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
                ->selectRaw('
                    COUNT(CASE WHEN plan_id = ? THEN 1 END) as bound_to_plan,
                    COUNT(CASE WHEN plan_id IS NULL THEN 1 END) as unbound,
                    COUNT(CASE WHEN amount = 0 THEN 1 END) as zero_amount,
                    COUNT(CASE WHEN amount > 0 THEN 1 END) as positive_amount,
                    AVG(amount) as avg_amount,
                    MAX(amount) as max_amount
                ', [$plan->id])
                ->first();

            $stats['plan_statistics'] = [
                'plan_id'         => $plan->id,
                'bound_to_plan'   => $planStats->bound_to_plan ?? 0,
                'unbound'         => $planStats->unbound ?? 0,
                'zero_amount'     => $planStats->zero_amount ?? 0,
                'positive_amount' => $planStats->positive_amount ?? 0,
                'avg_amount'      => round($planStats->avg_amount ?? 0, 2),
                'max_amount'      => $planStats->max_amount ?? 0
            ];

            // 如果有汇率信息，添加约束统计
            if ($plan->rate) {
                $stats['constraint_info'] = [
                    'constraint_type' => $plan->rate->amount_constraint,
                    'multiple_base'   => $plan->rate->multiple_base ?? null,
                    'min_amount'      => $plan->rate->min_amount ?? null,
                    'fixed_amounts'   => $plan->rate->fixed_amounts ?? null
                ];
            }
        }

        $stats['filtering_method'] = 'intersection_filtering';
        $stats['timestamp']        = now()->toISOString();

        return $stats;
    }

    /**
     * 测试专用：获取最优账号但不锁定（用于测试第6层筛选和排序逻辑）
     */
    public function findOptimalAccountForTest(
        ItunesTradePlan $plan,
        string          $roomId,
        array           $giftCardInfo,
        int             $currentDay = 1
    ): ?array
    {
        // 执行前5层筛选
        $giftCardAmount  = $giftCardInfo['amount'];
        $giftCardCountry = $giftCardInfo['country'] ?? $plan->country;

        $baseAccountIds = $this->getBaseQualifiedAccountIds($plan, $giftCardAmount, $giftCardCountry);
        if (empty($baseAccountIds)) return null;

        $constraintAccountIds = $this->getConstraintQualifiedAccountIds($baseAccountIds, $plan, $giftCardAmount);
        if (empty($constraintAccountIds)) return null;

        $roomBindingAccountIds = $this->getRoomBindingQualifiedAccountIds($constraintAccountIds, $plan, $giftCardInfo);
        if (empty($roomBindingAccountIds)) return null;

        $capacityAccountIds = $this->getCapacityQualifiedAccountIds($roomBindingAccountIds, $plan, $giftCardAmount);
        if (empty($capacityAccountIds)) return null;

        $dailyPlanAccountIds = $this->getDailyPlanQualifiedAccountIds($capacityAccountIds, $plan, $giftCardAmount, $currentDay);
        if (empty($dailyPlanAccountIds)) return null;

        // 第6层：只排序不锁定
        $optimalAccountIds = $this->sortAccountsByPriority($dailyPlanAccountIds, $plan, $roomId, $giftCardAmount);

        if (empty($optimalAccountIds)) return null;

        // 返回前3个最优账号的详细信息
        $topAccounts = [];
        for ($i = 0; $i < min(3, count($optimalAccountIds)); $i++) {
            $account = ItunesTradeAccount::find($optimalAccountIds[$i]);
            if ($account) {
                $topAccounts[] = [
                    'rank' => $i + 1,
                    'id' => $account->id,
                    'email' => $account->account,
                    'balance' => $account->amount,
                    'status' => $account->status,
                    'plan_id' => $account->plan_id,
                    'room_id' => $account->room_id,
                    'current_day' => $account->current_plan_day
                ];
            }
        }

        return [
            'total_candidates' => count($dailyPlanAccountIds),
            'top_accounts' => $topAccounts
        ];
    }

    /**
     * 获取各层筛选的详细统计（用于性能分析）
     */
    public function getFilteringPerformanceStats(
        ItunesTradePlan $plan,
        string          $roomId,
        array           $giftCardInfo,
        int             $currentDay = 1
    ): array
    {
        $giftCardAmount  = $giftCardInfo['amount'];
        $giftCardCountry = $giftCardInfo['country'] ?? $plan->country;
        $startTime       = microtime(true);

        $stats = [
            'plan_id'          => $plan->id,
            'gift_card_amount' => $giftCardAmount,
            'country'          => $giftCardCountry,
            'layers'           => []
        ];

        try {
            // 第1层统计
            $layer1Start    = microtime(true);
            $baseAccountIds = $this->getBaseQualifiedAccountIds($plan, $giftCardAmount, $giftCardCountry);
            $layer1Time     = (microtime(true) - $layer1Start) * 1000;

            $stats['layers']['base_qualification'] = [
                'qualified_count'   => count($baseAccountIds),
                'execution_time_ms' => round($layer1Time, 2)
            ];

            if (empty($baseAccountIds)) {
                $stats['total_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
                return $stats;
            }

            // 第2层统计
            $layer2Start          = microtime(true);
            $constraintAccountIds = $this->getConstraintQualifiedAccountIds($baseAccountIds, $plan, $giftCardAmount);
            $layer2Time           = (microtime(true) - $layer2Start) * 1000;

            $stats['layers']['constraint_qualification'] = [
                'qualified_count'   => count($constraintAccountIds),
                'execution_time_ms' => round($layer2Time, 2)
            ];

            if (empty($constraintAccountIds)) {
                $stats['total_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
                return $stats;
            }

            // 第3层统计
            $layer3Start           = microtime(true);
            $roomBindingAccountIds = $this->getRoomBindingQualifiedAccountIds($constraintAccountIds, $plan, $giftCardInfo);
            $layer3Time            = (microtime(true) - $layer3Start) * 1000;

            $stats['layers']['room_binding_qualification'] = [
                'qualified_count'   => count($roomBindingAccountIds),
                'execution_time_ms' => round($layer3Time, 2)
            ];

            if (empty($roomBindingAccountIds)) {
                $stats['total_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
                return $stats;
            }

            // 第4层统计
            $layer4Start        = microtime(true);
            $capacityAccountIds = $this->getCapacityQualifiedAccountIds($roomBindingAccountIds, $plan, $giftCardAmount);
            $layer4Time         = (microtime(true) - $layer4Start) * 1000;

            $stats['layers']['capacity_qualification'] = [
                'qualified_count'   => count($capacityAccountIds),
                'execution_time_ms' => round($layer4Time, 2)
            ];

            if (empty($capacityAccountIds)) {
                $stats['total_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
                return $stats;
            }

            // 第5层统计
            $layer5Start         = microtime(true);
            $dailyPlanAccountIds = $this->getDailyPlanQualifiedAccountIds($capacityAccountIds, $plan, $giftCardAmount, $currentDay);
            $layer5Time          = (microtime(true) - $layer5Start) * 1000;

            $stats['layers']['daily_plan_qualification'] = [
                'qualified_count'   => count($dailyPlanAccountIds),
                'execution_time_ms' => round($layer5Time, 2)
            ];

            $stats['final_qualified_count'] = count($dailyPlanAccountIds);
            $stats['total_time_ms']         = round((microtime(true) - $startTime) * 1000, 2);

        } catch (Exception $e) {
            $stats['error']         = $e->getMessage();
            $stats['total_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
        }

        return $stats;
    }
}
