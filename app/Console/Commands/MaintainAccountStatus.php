<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAppleAccountLoginJob;
use App\Jobs\ProcessAppleAccountLogoutJob;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * iTunes账号状态维护命令
 * 
 * 职责：
 * 1. 处理异常状态清理
 * 2. LOCKING状态转换
 * 3. 状态一致性检查
 * 4. 不涉及具体的登录/登出操作（通过队列处理）
 */
class MaintainAccountStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'itunes:maintain-status {--dry-run : 仅显示将要执行的操作，不实际执行}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'iTunes账号状态维护 - 处理异常状态和LOCKING状态转换';

    private bool $dryRun;

    /**
     * 执行控制台命令
     */
    public function handle(): void
    {
        $this->dryRun = $this->option('dry-run');
        $date = now();

        $this->getLogger()->info("========== iTunes账号状态维护开始 [{$date}] ==========");

        if ($this->dryRun) {
            $this->info("🔍 DRY RUN 模式：只显示操作，不实际执行");
        }

        try {
            // 第1步：清理异常状态
            $this->handleExceptionAccounts();

            // 第2步：处理 LOCKING 状态
            $this->processLockingAccounts();

            $this->getLogger()->info('iTunes账号状态维护完成');

        } catch (\Exception $e) {
            $this->getLogger()->error('状态维护过程中发生错误: ' . $e->getMessage());
            $this->getLogger()->error('错误详情', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 处理异常状态的账号
     */
    private function handleExceptionAccounts(): void
    {
        $this->getLogger()->info("=== 第1步：处理异常状态账号 ===");

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

                // 通过队列请求登出
                ProcessAppleAccountLogoutJob::dispatch($account->id, 'plan_deleted');
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
                // 通过队列请求登出
                ProcessAppleAccountLogoutJob::dispatch($account->id, 'already_completed');
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
        $this->getLogger()->info("=== 第2步：处理LOCKING状态账号 ===");

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

                // 通过队列请求登出
                ProcessAppleAccountLogoutJob::dispatch($account->id, 'daily_plan_completed');
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

        // 通过队列请求登出
        ProcessAppleAccountLogoutJob::dispatch($account->id, 'plan_completed');

        // 发送完成通知
        $msg = "[强]兑换目标达成通知\n";
        $msg .= "---------------------------------\n";
        $msg .= $account->account."\n";
        $msg .= "国家：{$account->country_code}   账户余款：{$currentTotalAmount}";

        try {
            send_msg_to_wechat('45958721463@chatroom', $msg);
        } catch (\Exception $e) {
            $this->getLogger()->error("发送微信通知失败: " . $e->getMessage());
        }

        $this->getLogger()->info('账号计划完成', [
            'account' => $account->account,
            'total_amount' => $currentTotalAmount,
            'completed_days' => $completedDays
        ]);
    }

    private function getLogger(): LoggerInterface
    {
        return Log::channel('kernel_process_accounts');
    }
} 