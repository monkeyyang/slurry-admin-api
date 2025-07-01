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

/**
 * iTunes账号状态管理 - 重构版
 *
 * 职责明确：
 * 1. 主要处理 LOCKING 和 WAITING 状态的账号
 * 2. 维护状态转换：LOCKING -> WAITING/PROCESSING, WAITING -> PROCESSING/下一天
 * 3. 推进日期变化
 * 4. 发送任务达成通知
 * 5. 清理异常状态（孤立账号、已完成账号等）
 */
class ProcessItunesAccountsV2 extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'itunes:process-accounts-v2 {--dry-run : 仅显示将要执行的操作，不实际执行}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '处理iTunes账号状态转换和日期推进 - 专注版（只处理LOCKING和WAITING状态）';

    protected GiftCardApiClient $giftCardApiClient;
    private bool $dryRun;
    private const TARGET_ZERO_AMOUNT_ACCOUNTS = 50; // 目标零余额账号数量

    /**
     * 执行控制台命令
     */
    public function handle(): void
    {
        $this->dryRun = $this->option('dry-run');
        $date = now();

        $this->getLogger()->info("========== iTunes账号状态管理开始 [{$date}] ==========");

        if ($this->dryRun) {
            $this->info("🔍 DRY RUN 模式：只显示操作，不实际执行");
        }

        try {
            $this->giftCardApiClient = app(GiftCardApiClient::class);

            // 第1步：维护零余额账号数量
            $this->maintainZeroAmountAccounts();

            // 第2步：清理异常状态
            $this->handleExceptionAccounts();

            // 第3步：处理 LOCKING 状态
            $this->processLockingAccounts();

            // 第4步：处理 WAITING 状态
            $this->processWaitingAccounts();

            $this->getLogger()->info('iTunes账号状态管理完成');

        } catch (\Exception $e) {
            $this->getLogger()->error('处理过程中发生错误: ' . $e->getMessage());
            $this->getLogger()->error('错误详情', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 维护零余额账号数量
     */
    private function maintainZeroAmountAccounts(): void
    {
        $this->getLogger()->info("=== 第1步：维护零余额账号数量 ===");

        // 获取当前零余额且登录有效的账号
        $currentZeroAmountAccounts = ItunesTradeAccount::where('amount', 0)
            ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->orderBy('created_at', 'desc')
            ->get();

        $currentZeroAmountCount = $currentZeroAmountAccounts->count();

        $this->getLogger()->info("📊 当前零余额且登录有效的账号统计", [
            'total_count' => $currentZeroAmountCount,
            'target_count' => self::TARGET_ZERO_AMOUNT_ACCOUNTS,
            'account_list' => $currentZeroAmountAccounts->pluck('account')->toArray()
        ]);

        // 显示当前零余额账号明细
        if ($currentZeroAmountCount > 0) {
            $this->info("✅ 当前零余额登录账号明细 ({$currentZeroAmountCount}个)：");
            foreach ($currentZeroAmountAccounts as $index => $account) {
                $this->info("   " . ($index + 1) . ". {$account->account} (ID: {$account->id}, 国家: {$account->country_code})");
            }
        } else {
            $this->warn("⚠️  当前没有零余额且登录有效的账号");
        }

        if ($currentZeroAmountCount >= self::TARGET_ZERO_AMOUNT_ACCOUNTS) {
            $this->info("🎯 目标零余额账号数量已达到 (" . self::TARGET_ZERO_AMOUNT_ACCOUNTS . ")，无需补充");
            return;
        }

        $needCount = self::TARGET_ZERO_AMOUNT_ACCOUNTS - $currentZeroAmountCount;
        $this->info("💰 需要补充 {$needCount} 个零余额登录账号");

        // 查找状态为processing且登录状态为invalid的零余额账号进行登录
        $candidateAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_INVALID)
            ->where('amount', 0)
            ->orderBy('created_at', 'asc') // 先导入的优先
            ->limit($needCount * 2) // 获取更多以防登录失败
            ->get();

        if ($candidateAccounts->isEmpty()) {
            $this->getLogger()->warning("❌ 未找到可用于登录的候选账号", [
                'search_criteria' => [
                    'status' => 'PROCESSING',
                    'login_status' => 'INVALID',
                    'amount' => 0
                ],
                'suggestion' => '可能需要导入更多零余额账号或检查现有账号状态'
            ]);
            return;
        }

        $this->getLogger()->info("🔍 找到候选登录账号", [
            'candidate_count' => $candidateAccounts->count(),
            'target_login_count' => $needCount,
            'account_list' => $candidateAccounts->pluck('account')->toArray()
        ]);

        // 显示候选账号明细
        $this->info("📋 候选登录账号明细 ({$candidateAccounts->count()}个)：");
        foreach ($candidateAccounts as $index => $account) {
            $createdDays = now()->diffInDays($account->created_at);
            $this->info("   " . ($index + 1) . ". {$account->account} (ID: {$account->id}, 国家: {$account->country_code}, 导入: {$createdDays}天前)");
        }

        // 批量创建登录队列任务
        if (!$this->dryRun) {
            $this->info("🚀 开始为候选账号创建登录任务...");
            $this->queueBatchLoginAccounts($candidateAccounts, $needCount);
        } else {
            $this->info("🔍 DRY RUN: 将为以下 {$candidateAccounts->count()} 个账号创建登录任务：");
            foreach ($candidateAccounts->take($needCount) as $index => $account) {
                $this->info("   " . ($index + 1) . ". {$account->account} -> 创建登录任务");
            }
        }
    }

    /**
     * 处理异常状态的账号
     */
    private function handleExceptionAccounts(): void
    {
        $this->getLogger()->info("=== 第2步：处理异常状态账号 ===");

        $this->handleOrphanedAccounts();
        $this->handleCompletedAccounts();
        $this->handleDataInconsistency();
    }

    /**
     * 处理孤立账号（计划已删除）
     */
    private function handleOrphanedAccounts(): void
    {
        $orphanedAccounts = ItunesTradeAccount::whereNotNull('plan_id')
            ->whereDoesntHave('plan')
            ->whereIn('status', [
                ItunesTradeAccount::STATUS_WAITING,
                ItunesTradeAccount::STATUS_PROCESSING,
                ItunesTradeAccount::STATUS_LOCKING
            ])
            ->get();

        if ($orphanedAccounts->isEmpty()) {
            $this->getLogger()->debug("没有发现孤立账号");
            return;
        }

        $this->getLogger()->warning("发现 {$orphanedAccounts->count()} 个孤立账号（计划已删除）");

        foreach ($orphanedAccounts as $account) {
            $this->info("🔧 孤立账号: {$account->account}");

            if (!$this->dryRun) {
                $account->timestamps = false;
                $account->update([
                    'plan_id' => null,
                    'current_plan_day' => null,
                    'status' => ItunesTradeAccount::STATUS_WAITING,
                ]);
                $account->timestamps = true;

                $this->requestAccountLogout($account, 'plan deleted');
            }
        }
    }

    /**
     * 处理已完成但仍登录的账号
     */
    private function handleCompletedAccounts(): void
    {
        $completedAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_COMPLETED)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->get();

        if ($completedAccounts->isEmpty()) {
            $this->getLogger()->debug("没有发现需要登出的已完成账号");
            return;
        }

        $this->getLogger()->info("发现 {$completedAccounts->count()} 个需要登出的已完成账号");

        foreach ($completedAccounts as $account) {
            $this->info("🔒 已完成账号需登出: {$account->account}");

            if (!$this->dryRun) {
                $this->requestAccountLogout($account, 'already completed');
            }
        }
    }

    /**
     * 处理数据不一致问题
     */
    private function handleDataInconsistency(): void
    {
        $inconsistentAccounts = [];

        $accounts = ItunesTradeAccount::whereIn('status', [
                ItunesTradeAccount::STATUS_WAITING,
                ItunesTradeAccount::STATUS_LOCKING
            ])
            ->whereNotNull('plan_id')
            ->whereNotNull('current_plan_day')
            ->where('current_plan_day', '>', 1)
            ->with('plan')
            ->get();

        foreach ($accounts as $account) {
            if (!$account->plan) continue;

            $currentDay = $account->current_plan_day;
            $previousDay = $currentDay - 1;

            $previousDayAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $previousDay)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');

            $previousDayLimit = $account->plan->daily_amounts[$previousDay - 1] ?? 0;

            if ($previousDayAmount < $previousDayLimit) {
                $inconsistentAccounts[] = [
                    'account' => $account,
                    'current_day' => $currentDay,
                    'previous_day' => $previousDay,
                ];
            }
        }

        if (empty($inconsistentAccounts)) {
            $this->getLogger()->debug("没有发现数据不一致的账号");
            return;
        }

        $this->getLogger()->warning("发现 " . count($inconsistentAccounts) . " 个数据不一致的账号");

        foreach ($inconsistentAccounts as $item) {
            $account = $item['account'];
            $this->warn("⚠️  数据不一致: {$account->account} -> 回退到第{$item['previous_day']}天");

            if (!$this->dryRun) {
                $account->timestamps = false;
                $account->update(['current_plan_day' => $item['previous_day']]);
                $account->timestamps = true;
            }
        }
    }

    /**
     * 处理 LOCKING 状态账号
     */
    private function processLockingAccounts(): void
    {
        $this->getLogger()->info("=== 第3步：处理LOCKING状态账号 ===");

        $lockingAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_LOCKING)
            ->with('plan')
            ->get();

        if ($lockingAccounts->isEmpty()) {
            $this->getLogger()->debug("没有LOCKING状态的账号");
            return;
        }

        $this->getLogger()->info("处理 {$lockingAccounts->count()} 个LOCKING状态账号");

        foreach ($lockingAccounts as $account) {
            $this->processLockingAccount($account);
        }
    }

    /**
     * 处理单个 LOCKING 状态账号
     */
    private function processLockingAccount(ItunesTradeAccount $account): void
    {
        $this->getLogger()->info("处理LOCKING账号: {$account->account}");

        // 1. 无计划账号直接转为PROCESSING
        if (!$account->plan) {
            $this->info("📝 无计划账号: {$account->account} -> PROCESSING");

            if (!$this->dryRun) {
                $account->timestamps = false;
                $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
                $account->timestamps = true;
            }
            return;
        }

        // 2. 检查是否已达到总目标
        if ($this->isAccountCompleted($account)) {
            $this->info("🎉 账号已完成: {$account->account} -> COMPLETED");

            if (!$this->dryRun) {
                $this->markAccountCompleted($account);
            }
            return;
        }

        // 3. 检查当日计划完成情况
        $currentDay = $account->current_plan_day ?? 1;
        $isDailyCompleted = $this->isDailyPlanCompleted($account, $currentDay);

        if ($isDailyCompleted) {
            $this->info("✅ 当日计划完成: {$account->account} (第{$currentDay}天) -> WAITING");

            if (!$this->dryRun) {
                $account->timestamps = false;
                $account->update(['status' => ItunesTradeAccount::STATUS_WAITING]);
                $account->timestamps = true;

                $this->requestAccountLogout($account, 'daily plan completed');
            }
        } else {
            $this->info("⏳ 当日计划未完成: {$account->account} (第{$currentDay}天) -> PROCESSING");

            if (!$this->dryRun) {
                $account->timestamps = false;
                $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
                $account->timestamps = true;
            }
        }
    }

    /**
     * 处理 WAITING 状态账号
     */
    private function processWaitingAccounts(): void
    {
        $this->getLogger()->info("=== 第4步：处理WAITING状态账号 ===");

        $waitingAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_WAITING)
            ->with('plan')
            ->get();

        if ($waitingAccounts->isEmpty()) {
            $this->getLogger()->debug("没有WAITING状态的账号");
            return;
        }

        $this->getLogger()->info("处理 {$waitingAccounts->count()} 个WAITING状态账号");

        foreach ($waitingAccounts as $account) {
            $this->processWaitingAccount($account);
            break;
        }
    }

    /**
     * 处理单个 WAITING 状态账号
     */
    private function processWaitingAccount(ItunesTradeAccount $account): void
    {
        $this->getLogger()->info("处理WAITING账号: {$account->account}");

        // 1. 检查是否已达到总目标
        if ($this->isAccountCompleted($account)) {
            $this->info("🎉 账号已完成: {$account->account} -> COMPLETED");

            if (!$this->dryRun) {
                $this->markAccountCompleted($account);
            }
            return;
        }

        // 2. 无计划账号 - 如果余额大于0则转为PROCESSING，否则保持等待
        if (!$account->plan) {
            if ($account->amount > 0) {
                $this->info("💸 无计划有余额账号: {$account->account} -> PROCESSING (可用于兑换)");

                if (!$this->dryRun) {
                    $account->timestamps = false;
                    $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
                    $account->timestamps = true;

                    $this->requestAccountLogin($account);
                }
            } else {
                $this->getLogger()->debug("无计划零余额账号保持等待: {$account->account}");
            }
            return;
        }

        $currentDay = $account->current_plan_day ?? 1;

        // 3. 检查是否有兑换记录
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        if (!$lastSuccessLog) {
            $this->info("🚀 新账号开始: {$account->account} -> PROCESSING (第{$currentDay}天)");

            if (!$this->dryRun) {
                $currentDay = max(1, $currentDay);
                $account->timestamps = false;
                $account->update([
                    'status' => ItunesTradeAccount::STATUS_PROCESSING,
                    'current_plan_day' => $currentDay
                ]);
                $account->timestamps = true;

                $this->requestAccountLogin($account);
            }
            return;
        }

        // 4. 检查时间间隔
        $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
        $now = now();

        $intervalMinutes = $lastExchangeTime->diffInMinutes($now);
        $intervalHours = $lastExchangeTime->diffInHours($now);

        $requiredExchangeInterval = max(1, $account->plan->exchange_interval ?? 5);
        $requiredDayInterval = max(1, $account->plan->day_interval ?? 24);

        // 5. 检查兑换间隔
        if ($intervalMinutes < $requiredExchangeInterval) {
            $remaining = $requiredExchangeInterval - $intervalMinutes;
            $this->getLogger()->debug("账号 {$account->account} 兑换间隔不足，还需 {$remaining} 分钟");
            return;
        }

        // 6. 检查当日计划完成情况
        $isDailyCompleted = $this->isDailyPlanCompleted($account, $currentDay);

        if (!$isDailyCompleted) {
            $this->info("⏳ 继续当日计划: {$account->account} -> PROCESSING (第{$currentDay}天)");

            if (!$this->dryRun) {
                $account->timestamps = false;
                $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
                $account->timestamps = true;

                $this->requestAccountLogin($account);
            }
            return;
        }

        // 7. 当日计划已完成，检查是否可以进入下一天
        if ($intervalHours < $requiredDayInterval) {
            $remaining = $requiredDayInterval - $intervalHours;
            $this->getLogger()->debug("账号 {$account->account} 天数间隔不足，还需 {$remaining} 小时");
            return;
        }

        // 8. 可以进入下一天
        $isLastDay = $currentDay >= $account->plan->plan_days;

        if ($isLastDay) {
            // 最后一天，检查是否超时
            if ($intervalHours >= 48) {
                $this->warn("⏰ 最后一天超时: {$account->account} -> 解绑计划");

                if (!$this->dryRun) {
                    $this->unbindAccountPlan($account);
                }
            } else {
                $this->getLogger()->debug("账号 {$account->account} 最后一天还在等待时间间隔");
            }
        } else {
            // 进入下一天
            $nextDay = $currentDay + 1;
            $this->info("📅 进入下一天: {$account->account} -> PROCESSING (第{$nextDay}天)");

            if (!$this->dryRun) {
                $this->advanceToNextDay($account);
            }
        }
    }

    /**
     * 检查账号是否已完成
     */
    private function isAccountCompleted(ItunesTradeAccount $account): bool
    {
        if (!$account->plan) {
            return false;
        }

        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        $currentTotalAmount = $lastSuccessLog ? $lastSuccessLog->after_amount : 0;
        return $currentTotalAmount >= $account->plan->total_amount;
    }

    /**
     * 检查当日计划是否完成
     */
    private function isDailyPlanCompleted(ItunesTradeAccount $account, int $currentDay): bool
    {
        if (!$account->plan) {
            return false;
        }

        $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        $dailyAmounts = $account->plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;

        return $dailyAmount >= $dailyLimit;
    }

    /**
     * 标记账号为已完成
     */
    private function markAccountCompleted(ItunesTradeAccount $account): void
    {
        // 更新completed_days
        $completedDays = [];
        if ($account->plan) {
            for ($day = 1; $day <= $account->plan->plan_days; $day++) {
                $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                    ->where('day', $day)
                    ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                    ->sum('amount');
                $completedDays[(string)$day] = $dailyAmount;
            }
        }

        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        $currentTotalAmount = $lastSuccessLog ? $lastSuccessLog->after_amount : 0;

        $account->timestamps = false;
        $account->update([
            'status' => ItunesTradeAccount::STATUS_COMPLETED,
            'current_plan_day' => null,
            'plan_id' => null,
            'completed_days' => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        // 请求登出
        $this->requestAccountLogout($account, 'plan completed');

        // 发送完成通知
        $msg = "[强]兑换目标达成通知\n";
        $msg .= "---------------------------------\n";
        $msg .= $account->account."\n";
        $msg .= "国家：{$account->country_code}   账户余款：{$currentTotalAmount}";

        send_msg_to_wechat('45958721463@chatroom', $msg);

        $this->getLogger()->info('账号计划完成', [
            'account' => $account->account,
            'total_amount' => $currentTotalAmount,
            'completed_days' => $completedDays
        ]);
    }

    /**
     * 进入下一天
     */
    private function advanceToNextDay(ItunesTradeAccount $account): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $nextDay = $currentDay + 1;

        // 更新completed_days
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
        for ($day = 1; $day <= $account->plan->plan_days; $day++) {
            $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $day)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');
            $completedDays[(string)$day] = $dailyAmount;
        }

        $account->timestamps = false;
        $account->update([
            'current_plan_day' => $nextDay,
            'status' => ItunesTradeAccount::STATUS_PROCESSING,
            'completed_days' => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        $this->requestAccountLogin($account);

        $this->getLogger()->info('账号进入下一天', [
            'account' => $account->account,
            'current_day' => $nextDay,
            'completed_days' => $completedDays
        ]);
    }

    /**
     * 解绑账号计划
     */
    private function unbindAccountPlan(ItunesTradeAccount $account): void
    {
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];

        if ($account->plan) {
            for ($day = 1; $day <= $account->plan->plan_days; $day++) {
                $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                    ->where('day', $day)
                    ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                    ->sum('amount');
                $completedDays[(string)$day] = $dailyAmount;
            }
        }

        $account->timestamps = false;
        $account->update([
            'plan_id' => null,
            'current_plan_day' => null,
            'status' => ItunesTradeAccount::STATUS_WAITING,
            'completed_days' => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        $this->requestAccountLogout($account, 'plan timeout unbound');

        $this->getLogger()->info('账号计划解绑', [
            'account' => $account->account,
            'reason' => '最后一天超时未完成',
            'completed_days' => $completedDays
        ]);
    }

    /**
     * 请求账号登录 - 使用队列
     */
    private function requestAccountLogin(ItunesTradeAccount $account): void
    {
        if ($account->login_status === ItunesTradeAccount::STATUS_LOGIN_ACTIVE) {
            $this->getLogger()->debug("账号 {$account->account} 已经登录，跳过登录请求");
            return;
        }

        try {
            $this->getLogger()->info("为账号 {$account->account} 创建登录队列任务", [
                'account_id' => $account->id,
                'account_email' => $account->account,
                'current_login_status' => $account->login_status,
                'amount' => $account->amount,
                'status' => $account->status
            ]);

            // 使用队列系统处理登录
            \App\Jobs\ProcessAppleAccountLoginJob::dispatch($account->id, 'status_transition');

            $this->getLogger()->info("✅ 账号 {$account->account} 登录队列任务已创建", [
                'account_id' => $account->id,
                'note' => '任务将在后台队列中处理，包含重试机制和轮询状态确认'
            ]);

        } catch (\Exception $e) {
            $this->getLogger()->error("❌ 账号 {$account->account} 创建登录队列任务异常: " . $e->getMessage(), [
                'account_id' => $account->id,
                'exception_type' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 批量创建登录队列任务
     */
    private function queueBatchLoginAccounts($accounts, int $targetCount): void
    {
        if ($accounts->isEmpty()) {
            $this->getLogger()->info("📋 批量登录：无账号需要处理");
            return;
        }

        $this->getLogger()->info("🚀 开始批量创建零余额账号登录队列任务", [
            'total_accounts' => $accounts->count(),
            'target_success_count' => $targetCount,
            'account_list' => $accounts->pluck('account')->toArray()
        ]);

        $loginTaskCount = 0;

        // 为每个账号创建单独的登录队列任务
        foreach ($accounts->take($targetCount * 2) as $index => $account) {
            try {
                // 检查是否需要登录
                if ($account->login_status === ItunesTradeAccount::STATUS_LOGIN_ACTIVE) {
                    $this->info("   " . ($index + 1) . ". {$account->account} -> 已经登录，跳过");
                    continue;
                }

                // 创建登录队列任务
                \App\Jobs\ProcessAppleAccountLoginJob::dispatch($account->id, 'zero_amount_maintenance');
                $loginTaskCount++;

                $this->info("   " . ($index + 1) . ". {$account->account} -> 登录队列任务已创建");
                
                $this->getLogger()->debug("账号登录队列任务详情", [
                    'account_id' => $account->id,
                    'account' => $account->account,
                    'country_code' => $account->country_code,
                    'reason' => 'zero_amount_maintenance'
                ]);

                // 如果已经创建了足够的任务，停止
                if ($loginTaskCount >= $targetCount) {
                    break;
                }

            } catch (\Exception $e) {
                $this->getLogger()->error("❌ 为账号 {$account->account} 创建登录队列任务失败", [
                    'account_id' => $account->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->getLogger()->info("✅ 零余额账号登录队列任务创建完成", [
            'created_tasks' => $loginTaskCount,
            'target_count' => $targetCount,
            'note' => '任务将在后台队列中异步处理，包含重试机制和失败通知'
        ]);

        $this->info("🎯 零余额账号登录队列任务总结:");
        $this->info("   创建任务数: {$loginTaskCount}");
        $this->info("   目标成功数: {$targetCount}");
        $this->info("   处理方式: 后台队列异步处理（支持重试和轮询确认）");
        $this->info("   特性: 防重复处理、智能重试、失败通知");
    }





    /**
     * 请求账号登出
     */
    private function requestAccountLogout(ItunesTradeAccount $account, string $reason = ''): void
    {
        if ($account->login_status === ItunesTradeAccount::STATUS_LOGIN_INVALID) {
            return;
        }

        try {
            $logoutData = [['username' => $account->account]];
            $response = $this->giftCardApiClient->deleteUserLogins($logoutData);

            if ($response['code'] === 0) {
                $account->update(['login_status' => ItunesTradeAccount::STATUS_LOGIN_INVALID]);
                $this->getLogger()->info("账号 {$account->account} 登出成功" . ($reason ? " ({$reason})" : ''));
            }
        } catch (\Exception $e) {
            $this->getLogger()->error("账号 {$account->account} 请求登出失败: " . $e->getMessage());
        }
    }

    private function getLogger(): LoggerInterface
    {
        return Log::channel('kernel_process_accounts');
    }
}
