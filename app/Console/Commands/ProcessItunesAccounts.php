<?php

namespace App\Console\Commands;

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use App\Models\ItunesTradePlan;
use App\Services\GiftCardApiClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class ProcessItunesAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'itunes:process-accounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '处理iTunes账号状态转换 - 每5分钟执行';

    protected GiftCardApiClient $giftCardApiClient;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $date = now();
        $this->getLogger()->info("==================================[{$date}]===============================");
        $this->getLogger()->info("开始处理iTunes账号状态转换...");

        try {
            $this->giftCardApiClient = app(GiftCardApiClient::class);

            // 获取需要处理的账号（只处理LOCKING和WAITING状态）
            $accounts = ItunesTradeAccount::whereIn('status', [
                ItunesTradeAccount::STATUS_LOCKING,
                ItunesTradeAccount::STATUS_WAITING
            ])
            ->with('plan')
            ->get();

            // 额外查找有plan_id但计划被删除的账号（只处理WAITING和PROCESSING状态）
            $orphanedAccounts = ItunesTradeAccount::whereNotNull('plan_id')
                ->whereDoesntHave('plan')
                ->whereIn('status', [
                    ItunesTradeAccount::STATUS_WAITING,
                    ItunesTradeAccount::STATUS_PROCESSING
                ])
                ->get();

            // 合并两个集合
            $allAccounts = $accounts->merge($orphanedAccounts)->unique('id');

            $this->getLogger()->info("找到 {$accounts->count()} 个LOCKING/WAITING状态账号，{$orphanedAccounts->count()} 个计划被删除的账号，共 {$allAccounts->count()} 个需要处理的账号");

            foreach ($allAccounts as $account) {
                try {
                    $this->processAccount($account);
                } catch (\Exception $e) {
                    $this->getLogger()->error("处理账号 {$account->account} 失败: " . $e->getMessage());
                }
            }

            $this->getLogger()->info('iTunes账号状态处理完成');

        } catch (\Exception $e) {
            $this->getLogger()->error('处理过程中发生错误: ' . $e->getMessage());
            $this->getLogger()->error('iTunes账号状态处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 获取专用日志实例
     */
    private function getLogger(): LoggerInterface
    {
        return Log::channel('kernel_process_accounts');
    }

    /**
     * 处理单个账号
     */
    private function processAccount(ItunesTradeAccount $account): void
    {
        // 1. 检查是否有待处理任务，有则跳过
        if ($this->hasPendingTasks($account)) {
            $this->getLogger()->info("账号 {$account->account} 有待处理任务，跳过处理");
            return;
        }

        // 2. 处理计划被删除的账号解绑
        if ($this->handleDeletedPlanUnbinding($account)) {
            return; // 如果处理了计划解绑，则跳过后续处理
        }

        // 3. 修复数据不一致问题
        //$this->fixDataInconsistency($account);

        // 4. 根据状态处理
        if ($account->status === ItunesTradeAccount::STATUS_LOCKING) {
            $this->processLockingAccount($account);
        } elseif ($account->status === ItunesTradeAccount::STATUS_WAITING) {
            $this->processWaitingAccount($account);
        }
    }

    /**
     * 处理计划被删除的账号解绑
     */
    private function handleDeletedPlanUnbinding(ItunesTradeAccount $account): bool
    {
        // 检查账号是否有plan_id但plan已被删除
        if ($account->plan_id && !$account->plan) {
            $this->getLogger()->warning("发现计划被删除的账号", [
                'account' => $account->account,
                'plan_id' => $account->plan_id,
                'current_plan_day' => $account->current_plan_day,
                'status' => $account->status,
                'issue' => '计划已被删除，需要解绑'
            ]);

            // 解绑计划并重置相关字段
            $account->update([
                'plan_id' => null,
                //'current_plan_day' => null,
                'status' => ItunesTradeAccount::STATUS_WAITING,
                // 'completed_days' => null
            ]);

            $this->getLogger()->info("账号 {$account->account} 计划解绑完成", [
                'action' => '清除plan_id',
                'new_status' => ItunesTradeAccount::STATUS_WAITING,
                'reason' => '关联的计划已被删除'
            ]);

            return true; // 返回true表示已处理
        }

        return false; // 返回false表示无需处理
    }

    /**
     * 修复数据不一致问题
     */
    private function fixDataInconsistency(ItunesTradeAccount $account): void
    {
        if (!$account->plan) {
            return;
        }

        $currentDay = $account->current_plan_day ?? 1;
        $planDays = $account->plan->plan_days;

        // 检查当前天数是否超过计划天数
        if ($currentDay > $planDays) {
            $this->getLogger()->warning("发现数据不一致", [
                'account' => $account->account,
                'current_plan_day' => $currentDay,
                'plan_days' => $planDays,
                'issue' => '当前天数超过计划天数'
            ]);

            // 检查账号是否应该完成
            if ($this->shouldAccountBeCompleted($account)) {
                $this->markAccountCompleted($account);
                $this->getLogger()->info("账号 {$account->account} 已修复并标记为完成");
                return;
            }

            // 如果不应该完成，将当前天数重置为计划的最后一天
            $account->update(['current_plan_day' => $planDays]);

            $this->getLogger()->info("账号 {$account->account} 当前天数已修复", [
                'old_current_day' => $currentDay,
                'new_current_day' => $planDays,
                'plan_days' => $planDays
            ]);

            // 刷新账号数据
            $account->refresh();
        }
    }

    /**
     * 判断账号是否应该完成
     */
    private function shouldAccountBeCompleted(ItunesTradeAccount $account): bool
    {
        if (!$account->plan) {
            return false;
        }

        // 检查总金额是否达到计划要求
        if ($account->amount >= $account->plan->total_amount) {
            return true;
        }

        // 检查是否所有计划天数都有成功的兑换记录
        $completedDaysWithData = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->distinct('day')
            ->whereBetween('day', [1, $account->plan->plan_days])
            ->count();

        // 如果所有天都有兑换记录，认为应该完成
        return $completedDaysWithData >= $account->plan->plan_days;
    }

    /**
     * 检查账号是否有待处理任务
     */
    private function hasPendingTasks(ItunesTradeAccount $account): bool
    {
        $pendingCount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_PENDING)
            ->count();

        return $pendingCount > 0;
    }

    /**
     * 处理锁定状态的账号
     */
    private function processLockingAccount(ItunesTradeAccount $account): void
    {
        $this->getLogger()->info("处理锁定状态账号: {$account->account}");

        // 获取最后一条成功日志
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        if (!$lastSuccessLog) {
            $this->getLogger()->info("账号 {$account->account} 没有成功的兑换记录，更新状态为PROCESSING");
            $account->update(['status' => 'processing']);
            return;
        }

        // 更新completed_days字段
        $this->updateCompletedDays($account, $lastSuccessLog);

        // 检查账号总金额是否达到计划金额
        if ($this->isAccountCompleted($account)) {
            $this->markAccountCompleted($account);
            return;
        }

        // 检查账号当日金额是否达到计划当日金额

        // 状态改为等待
        $account->update(['status' => ItunesTradeAccount::STATUS_WAITING]);

        $this->getLogger()->info('锁定账号状态转换为等待', [
            'account_id' => $account->account,
            'account' => $account->account,
            'status_changed' => 'LOCKING -> WAITING',
            'reason' => '处理完成，转为等待状态'
        ]);
    }

    /**
     * 处理等待状态的账号
     */
    private function processWaitingAccount(ItunesTradeAccount $account): void
    {
        $this->getLogger()->info("处理等待状态账号: {$account->account}");

        if (!$account->plan) {
            $this->getLogger()->warning("账号 {$account->account} 没有关联的计划，清除计划相关字段", [
                'account_id' => $account->account,
                'old_status' => $account->status,
                'plan_id' => $account->plan_id,
                'current_plan_day' => $account->current_plan_day
            ]);

            // 清除计划相关字段，设为等待状态
            $account->update([
                'plan_id' => null,
                'current_plan_day' => null,
                'status' => ItunesTradeAccount::STATUS_WAITING
            ]);
            return;
        }

        // 验证计划配置的完整性
        if (!$this->validatePlanConfiguration($account->plan)) {
            $this->getLogger()->error("账号 {$account->account} 的计划配置不完整，标记为完成", [
                'plan_id' => $account->plan->id,
                'reason' => '计划配置验证失败'
            ]);
            $this->markAccountCompleted($account);
            return;
        }

        // 获取最后一条成功日志
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        if (!$lastSuccessLog) {
            // 没有成功兑换记录的账号，设为第1天处理中状态
            $account->update([
                'status' => ItunesTradeAccount::STATUS_PROCESSING,
                'current_plan_day' => 1
            ]);
            $this->getLogger()->info("账号 {$account->account} 没有成功的兑换记录，设为第1天处理中状态", [
                'account_id' => $account->account,
                'old_status' => 'WAITING',
                'new_status' => 'PROCESSING',
                'current_plan_day' => 1,
                'reason' => '无兑换记录，开始计划执行'
            ]);
            return;
        }

        $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
        $now = now();

        // 计算时间间隔（分钟）
        $intervalMinutes = $lastExchangeTime->diffInMinutes($now);
        $requiredExchangeInterval = max(1, $account->plan->exchange_interval ?? 5); // 最少1分钟

        $this->getLogger()->info("账号 {$account->account} 时间检查: 间隔 {$intervalMinutes} 分钟, 兑换间隔要求 {$requiredExchangeInterval} 分钟");

        // 检查是否满足兑换间隔时间
        if ($intervalMinutes < $requiredExchangeInterval) {
            $this->getLogger()->info("账号 {$account->account} 兑换间隔时间不足，保持等待状态");
            return;
        }

        // 满足兑换间隔，检查日期间隔
        $intervalHours = $lastExchangeTime->diffInHours($now);
        $requiredDayInterval = max(1, $account->plan->day_interval ?? 24); // 最少1小时

        $this->getLogger()->info("账号 {$account->account} 日期检查: 间隔 {$intervalHours} 小时, 日期间隔要求 {$requiredDayInterval} 小时");

        // 检查是否超过最大等待时间（防止无限等待）
        $maxWaitingHours = max($requiredDayInterval * 2, 48); // 最大等待时间为日期间隔的2倍，但不少于48小时
        if ($intervalHours >= $maxWaitingHours) {
            $this->getLogger()->warning("账号 {$account->account} 等待时间过长，强制标记为完成", [
                'interval_hours' => $intervalHours,
                'max_waiting_hours' => $maxWaitingHours,
                'current_day' => $account->current_plan_day ?? 1,
                'plan_days' => $account->plan->plan_days,
                'reason' => '超过最大等待时间限制'
            ]);
            $this->markAccountCompleted($account);
            return;
        }

        // 检查是否是计划的最后一天
        $currentDay = $account->current_plan_day ?? 1;
        $isLastDay = $currentDay >= $account->plan->plan_days;

        if ($intervalHours >= $requiredDayInterval) {
            if ($isLastDay) {
                // 最后一天且超过日期间隔，标记为完成
                $this->getLogger()->info("账号 {$account->account} 处于计划最后一天且超过日期间隔，标记为完成", [
                    'current_day' => $currentDay,
                    'plan_days' => $account->plan->plan_days,
                    'interval_hours' => $intervalHours,
                    'required_day_interval' => $requiredDayInterval,
                    'reason' => '最后一天超时完成'
                ]);
                $this->markAccountCompleted($account);
            } else {
                // 非最后一天，进入下一天
                $this->advanceToNextDay($account);
            }
        } else {
            // 未超过日期间隔，检查当日计划是否完成
            $this->checkDailyPlanCompletion($account);
        }
    }

    /**
     * 验证计划配置的完整性
     */
    private function validatePlanConfiguration($plan): bool
    {
        // 检查基本配置
        if (empty($plan->plan_days) || $plan->plan_days <= 0) {
            $this->getLogger()->error("计划配置错误: plan_days 无效", [
                'plan_id' => $plan->id,
                'plan_days' => $plan->plan_days
            ]);
            return false;
        }

        if (empty($plan->total_amount) || $plan->total_amount <= 0) {
            $this->getLogger()->error("计划配置错误: total_amount 无效", [
                'plan_id' => $plan->id,
                'total_amount' => $plan->total_amount
            ]);
            return false;
        }

        // 检查daily_amounts配置
        $dailyAmounts = $plan->daily_amounts ?? [];
        if (empty($dailyAmounts) || !is_array($dailyAmounts)) {
            $this->getLogger()->error("计划配置错误: daily_amounts 无效", [
                'plan_id' => $plan->id,
                'daily_amounts' => $dailyAmounts
            ]);
            return false;
        }

        if (count($dailyAmounts) != $plan->plan_days) {
            $this->getLogger()->error("计划配置错误: daily_amounts 数量与 plan_days 不匹配", [
                'plan_id' => $plan->id,
                'daily_amounts_count' => count($dailyAmounts),
                'plan_days' => $plan->plan_days
            ]);
            return false;
        }

        // 检查每日金额是否合理
        foreach ($dailyAmounts as $index => $amount) {
            if (!is_numeric($amount) || $amount < 0) {
                $this->getLogger()->error("计划配置错误: daily_amounts 中存在无效金额", [
                    'plan_id' => $plan->id,
                    'day' => $index + 1,
                    'amount' => $amount
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * 获取最小兑换金额
     */
    private function getMinRedemptionAmount(ItunesTradePlan $plan): float
    {
        // 默认最小兑换金额
        $defaultMinAmount = 50.0;

        try {
            // 获取计划关联的汇率
            $rate = $plan->rate;
            if (!$rate) {
                $this->getLogger()->warning("计划 {$plan->id} 没有关联的汇率，使用默认最小兑换金额: {$defaultMinAmount}");
                return $defaultMinAmount;
            }

            $minAmount = $defaultMinAmount;
            $planFloatAmount = (float)($plan->float_amount ?? 0);

            // 根据汇率约束类型处理
            if ($rate->amount_constraint === 'fixed') {
                // 固定金额类型：读取 fixed_amounts JSON
                $fixedAmounts = $rate->fixed_amounts;

                // 如果是字符串，尝试JSON解码
                if (is_string($fixedAmounts)) {
                    $decodedAmounts = json_decode($fixedAmounts, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAmounts)) {
                        $fixedAmounts = $decodedAmounts;
                    }
                }

                if (is_array($fixedAmounts) && !empty($fixedAmounts)) {
                    // 使用固定面额中的最小值，减去浮动金额作为最小判断标准
                    $minFixedAmount = min(array_map('floatval', $fixedAmounts));
                    // 考虑浮动金额：最小兑换金额 = 最小固定面额 - 浮动金额
                    $minAmount = max($defaultMinAmount, $minFixedAmount - $planFloatAmount);

                    $this->getLogger()->info("固定金额类型最小兑换金额计算", [
                        'fixed_amounts' => $fixedAmounts,
                        'min_fixed_amount' => $minFixedAmount,
                        'plan_float_amount' => $planFloatAmount,
                        'calculated_min_amount' => $minAmount
                    ]);
                } else {
                    $this->getLogger()->warning("固定金额类型但fixed_amounts为空，使用默认值");
                }

            } elseif ($rate->amount_constraint === 'multiple') {
                // 倍数类型：读取 min_amount，结合浮动金额判断
                $rateMinAmount = (float)($rate->min_amount ?? 0);

                if ($rateMinAmount > 0) {
                    // 考虑浮动金额：最小兑换金额 = 汇率最小金额 - 浮动金额
                    $minAmount = max($defaultMinAmount, $rateMinAmount - $planFloatAmount);

                    $this->getLogger()->info("倍数类型最小兑换金额计算", [
                        'rate_min_amount' => $rateMinAmount,
                        'plan_float_amount' => $planFloatAmount,
                        'calculated_min_amount' => $minAmount
                    ]);
                } else {
                    // 如果没有设置min_amount，使用multiple_base作为参考
                    $multipleBase = (float)($rate->multiple_base ?? 0);
                    if ($multipleBase > 0) {
                        $minAmount = max($defaultMinAmount, $multipleBase - $planFloatAmount);

                        $this->getLogger()->info("倍数类型使用multiple_base计算最小兑换金额", [
                            'multiple_base' => $multipleBase,
                            'plan_float_amount' => $planFloatAmount,
                            'calculated_min_amount' => $minAmount
                        ]);
                    } else {
                        $this->getLogger()->warning("倍数类型但min_amount和multiple_base都为空，使用默认值");
                    }
                }

            } else {
                // 其他类型（all等），使用汇率的min_amount
                $rateMinAmount = (float)($rate->min_amount ?? 0);
                if ($rateMinAmount > 0) {
                    $minAmount = max($defaultMinAmount, $rateMinAmount - $planFloatAmount);
                } else {
                    $minAmount = $defaultMinAmount;
                }

                $this->getLogger()->info("其他类型最小兑换金额计算", [
                    'amount_constraint' => $rate->amount_constraint,
                    'rate_min_amount' => $rateMinAmount,
                    'plan_float_amount' => $planFloatAmount,
                    'calculated_min_amount' => $minAmount
                ]);
            }

            // 确保最小兑换金额不会小于1
            $minAmount = max(1.0, $minAmount);

            $this->getLogger()->info("计划 {$plan->id} 最小兑换金额计算完成", [
                'plan_id' => $plan->id,
                'rate_id' => $rate->id,
                'amount_constraint' => $rate->amount_constraint,
                'plan_float_amount' => $planFloatAmount,
                'final_min_amount' => $minAmount
            ]);

            return $minAmount;

        } catch (\Exception $e) {
            $this->getLogger()->error("获取最小兑换金额失败，使用默认值: {$defaultMinAmount}", [
                'plan_id' => $plan->id,
                'error' => $e->getMessage()
            ]);
            return $defaultMinAmount;
        }
    }

    /**
     * 更新completed_days字段
     */
    private function updateCompletedDays(ItunesTradeAccount $account, ItunesTradeAccountLog $lastSuccessLog): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $plan = $account->plan;

        if (!$plan) {
            $this->getLogger()->warning("账号 {$account->account} 没有关联的计划，无法更新completed_days");
            return;
        }

        // 获取现有的completed_days数据
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];

        // 根据计划天数，更新每一天的数据
        for ($day = 1; $day <= $plan->plan_days; $day++) {
            // 计算该天的累计兑换金额
            $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $day)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');

            // 更新该天的数据
            $completedDays[(string)$day] = $dailyAmount;

            $this->getLogger()->info("更新账号 {$account->account} 第{$day}天完成情况: {$dailyAmount}");
        }

        // 保存更新后的completed_days
        $account->update(['completed_days' => json_encode($completedDays)]);

        $this->getLogger()->info("账号 {$account->account} 所有天数数据已更新", [
            'plan_days' => $plan->plan_days,
            'current_day' => $currentDay,
            'completed_days' => $completedDays
        ]);
    }

    /**
     * 检查账号是否完成
     */
    private function isAccountCompleted(ItunesTradeAccount $account): bool
    {
        if (!$account->plan) {
            return false;
        }

        return $account->amount >= $account->plan->total_amount;
    }

    /**
     * 标记账号为完成状态
     */
    private function markAccountCompleted(ItunesTradeAccount $account): void
    {
        if (!$account->plan) {
            $this->getLogger()->warning("账号 {$account->account} 没有关联的计划，无法标记为完成");
            return;
        }

        // 获取现有的completed_days数据
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];

        // 根据计划天数，更新每一天的数据
        for ($day = 1; $day <= $account->plan->plan_days; $day++) {
            // 计算该天的累计兑换金额
            $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $day)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');

            // 更新该天的数据
            $completedDays[(string)$day] = $dailyAmount;
        }

        $account->update([
            'status' => ItunesTradeAccount::STATUS_COMPLETED,
            'current_plan_day' => null,
            'plan_id' => null,
            'completed_days' => json_encode($completedDays),
        ]);

        $this->getLogger()->info('账号计划完成', [
            'account_id' => $account->account,
            'account' => $account->account,
            'total_amount' => $account->amount,
            'plan_total_amount' => $account->plan->total_amount ?? 0,
            'plan_days' => $account->plan->plan_days,
            'final_completed_days' => $completedDays
        ]);

        // 删除登录态
        try {
            $loginAccount = [
                'id' => $account->id,
                'username' => $account->account,
                'password' => $account->getDecryptedPassword(),
                'verify_url' => $account->api_url
            ];
            $this->giftCardApiClient->deleteUserLogins($loginAccount);
        } catch (\Exception $e) {
            $this->getLogger()->warning("删除账号 {$account->account} 登录态失败: " . $e->getMessage());
        }

        // 发送完成通知
        $msg = "[强]兑换目标达成通知\n";
        $msg .= "---------------\n";
        $msg .= $account->account."[".$account->amount."]";

        send_msg_to_wechat('44769140035@chatroom', $msg);
    }

    /**
     * 进入下一天
     */
    private function advanceToNextDay(ItunesTradeAccount $account): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $nextDay = $currentDay + 1;

        if (!$account->plan) {
            $this->getLogger()->warning("账号 {$account->account} 没有关联的计划，无法进入下一天");
            return;
        }

        // 检查是否已经是最后一天或超过计划天数
        if ($currentDay >= $account->plan->plan_days) {
            $this->getLogger()->warning("账号 {$account->account} 已达到或超过计划最后一天，标记为完成", [
                'current_day' => $currentDay,
                'plan_days' => $account->plan->plan_days,
                'reason' => '达到计划天数上限'
            ]);
            $this->markAccountCompleted($account);
            return;
        }

        // 获取现有的completed_days数据
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];

        // 根据计划天数，更新每一天的数据
        for ($day = 1; $day <= $account->plan->plan_days; $day++) {
            // 计算该天的累计兑换金额
            $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $day)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');

            // 更新该天的数据
            $completedDays[(string)$day] = $dailyAmount;
        }

        $account->update([
            'current_plan_day' => $nextDay,
            'status' => ItunesTradeAccount::STATUS_PROCESSING,
            'completed_days' => json_encode($completedDays),
        ]);

        $this->getLogger()->info('账号进入下一天', [
            'account_id' => $account->account,
            'account' => $account->account,
            'current_day' => $nextDay,
            'plan_days' => $account->plan->plan_days,
            'status_changed' => 'WAITING -> PROCESSING',
            'reason' => '超过日期间隔，进入下一天',
            'completed_days' => $completedDays
        ]);
    }

    /**
     * 检查当日计划完成情况
     */
    private function checkDailyPlanCompletion(ItunesTradeAccount $account): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $plan = $account->plan;

        // 计算当天累计兑换金额
        $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        // 获取当天的计划额度
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;

        $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天计划检查: 已兑换 {$dailyAmount}, 目标 {$dailyLimit}");

        // 处理配置异常情况：如果当日目标为0或负数，认为当日已完成
        if ($dailyLimit <= 0) {
            $this->getLogger()->warning("账号 {$account->account} 第{$currentDay}天目标金额配置异常 ({$dailyLimit})，认为当日已完成", [
                'current_day' => $currentDay,
                'daily_limit' => $dailyLimit,
                'plan_id' => $plan->id
            ]);

            // 检查是否是最后一天
            if ($currentDay >= $plan->plan_days) {
                $this->getLogger()->info("账号 {$account->account} 最后一天配置异常但认为完成，标记账号为完成");
                $this->markAccountCompleted($account);
            } else {
                // 检查总金额是否已达标
                if ($this->isAccountCompleted($account)) {
                    $this->getLogger()->info("账号 {$account->account} 总金额已达标，标记为完成");
                    $this->markAccountCompleted($account);
                } else {
                    $this->getLogger()->info("账号 {$account->account} 当日配置异常，保持等待状态");
                }
            }
            return;
        }

        if ($dailyAmount >= $dailyLimit) {
            // 当日计划已完成，检查是否是最后一天
            if ($currentDay >= $plan->plan_days) {
                // 最后一天计划完成，标记账号为完成
                $this->getLogger()->info("账号 {$account->account} 最后一天计划完成，标记为完成", [
                    'current_day' => $currentDay,
                    'plan_days' => $plan->plan_days,
                    'daily_amount' => $dailyAmount,
                    'daily_limit' => $dailyLimit,
                    'reason' => '最后一天计划完成'
                ]);
                $this->markAccountCompleted($account);
            } else {
                // 非最后一天，检查总金额是否已达标
                if ($this->isAccountCompleted($account)) {
                    $this->getLogger()->info("账号 {$account->account} 总金额已达标，标记为完成", [
                        'current_day' => $currentDay,
                        'total_amount' => $account->amount,
                        'plan_total_amount' => $plan->total_amount,
                        'reason' => '总金额达标完成'
                    ]);
                    $this->markAccountCompleted($account);
                } else {
                    $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天计划已完成，等待下一天", [
                        'current_day' => $currentDay,
                        'plan_days' => $plan->plan_days,
                        'daily_amount' => $dailyAmount,
                        'daily_limit' => $dailyLimit,
                        'status' => '保持等待状态直到满足日期间隔'
                    ]);
                }
            }
        } else {
            // 计划未完成，检查账号是否还有足够余额继续兑换
            $remainingDaily = $dailyLimit - $dailyAmount;
            $accountBalance = $account->amount ?? 0;

            $this->getLogger()->info("账号 {$account->account} 当日计划未完成检查", [
                'current_day' => $currentDay,
                'daily_amount' => $dailyAmount,
                'daily_limit' => $dailyLimit,
                'remaining_daily' => $remainingDaily,
                'account_balance' => $accountBalance
            ]);

            // 获取计划关联的汇率配置，确定最小兑换金额
            $minRedemptionAmount = $this->getMinRedemptionAmount($plan);

            // 如果剩余日额度小于最小兑换金额或账号余额不足，认为当日已完成
            if ($remainingDaily < $minRedemptionAmount || $accountBalance < $minRedemptionAmount) {
                $this->getLogger()->info("账号 {$account->account} 剩余额度或余额不足，认为当日计划完成", [
                    'remaining_daily' => $remainingDaily,
                    'account_balance' => $accountBalance,
                    'min_redemption_amount' => $minRedemptionAmount,
                    'reason' => $remainingDaily < $minRedemptionAmount ? '剩余日额度不足' : '账号余额不足'
                ]);

                // 检查是否是最后一天
                if ($currentDay >= $plan->plan_days) {
                    $this->getLogger()->info("账号 {$account->account} 最后一天余额不足，标记为完成");
                    $this->markAccountCompleted($account);
                } else {
                    // 检查总金额是否已达标
                    if ($this->isAccountCompleted($account)) {
                        $this->getLogger()->info("账号 {$account->account} 总金额已达标，标记为完成");
                        $this->markAccountCompleted($account);
                    } else {
                        $this->getLogger()->info("账号 {$account->account} 当日余额不足，保持等待状态");
                        // 保持等待状态，不改变状态
                    }
                }
            } else {
                // 余额充足，状态改为processing
                $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);

                $this->getLogger()->info('等待账号状态转换为执行中', [
                    'account_id' => $account->account,
                    'account' => $account->account,
                    'current_day' => $currentDay,
                    'status_changed' => 'WAITING -> PROCESSING',
                    'reason' => '当日计划未完成且余额充足，转为执行状态'
                ]);
            }
        }
    }
}
