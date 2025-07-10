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
 * iTunes账号日期推进命令
 *
 * 职责：
 * 1. 处理WAITING状态账号的日期推进
 * 2. 推进天数和解绑过期计划
 * 3. 通过队列处理登录/登出
 *
 * 注意：30分钟间隔由外部调度控制（每30分钟执行），无需内部检查
 */
class AdvanceAccountDays extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'itunes:advance-days {--dry-run : 仅显示将要执行的操作，不实际执行}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'iTunes账号日期推进 - 处理WAITING状态账号的日期推进（30分钟调度控制间隔）';

    private bool $dryRun;

    /**
     * 执行控制台命令
     */
    public function handle(): void
    {
        $this->dryRun = $this->option('dry-run');
        $date         = now();

        $this->getLogger()->info("========== iTunes账号日期推进开始 [{$date}] ==========");

        if ($this->dryRun) {
            $this->info("🔍 DRY RUN 模式：只显示操作，不实际执行");
        }

        try {
            // 处理 WAITING 状态账号
            $this->processWaitingAccounts();

            $this->getLogger()->info('iTunes账号日期推进完成');

        } catch (\Exception $e) {
            $this->getLogger()->error('日期推进过程中发生错误: ' . $e->getMessage());
            $this->getLogger()->error('错误详情', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 处理 WAITING 状态账号
     */
    private function processWaitingAccounts(): void
    {
        $this->getLogger()->info("=== 处理WAITING状态账号 ===");

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

                    // 通过队列请求登录
                    ProcessAppleAccountLoginJob::dispatch($account->id, 'no_plan_with_balance');
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
                $currentDay          = max(1, $currentDay);
                $account->timestamps = false;
                $account->update([
                    'status'           => ItunesTradeAccount::STATUS_PROCESSING,
                    'current_plan_day' => $currentDay
                ]);
                $account->timestamps = true;

                // 通过队列请求登录
                ProcessAppleAccountLoginJob::dispatch($account->id, 'new_account_start');
            }
            return;
        }

        // 4. 检查当日计划完成情况
        $isDailyCompleted = $this->isDailyPlanCompleted($account, $currentDay);

        if (!$isDailyCompleted) {
            $this->info("⏳ 继续当日计划: {$account->account} -> PROCESSING (第{$currentDay}天)");

            if (!$this->dryRun) {
                $account->timestamps = false;
                $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
                $account->timestamps = true;

                // 通过队列请求登录
                ProcessAppleAccountLoginJob::dispatch($account->id, 'continue_daily_plan');
            }
            return;
        }

        // 5. 当日计划已完成，检查天数间隔（用于推进天数）
        $lastExchangeTime    = Carbon::parse($lastSuccessLog->exchange_time);
        $now                 = now();
        $intervalHours       = $lastExchangeTime->diffInHours($now);
        $requiredDayInterval = max(1, $account->plan->day_interval ?? 24);

        if ($intervalHours < $requiredDayInterval) {
            $remaining = $requiredDayInterval - $intervalHours;
            $this->getLogger()->debug("账号 {$account->account} 天数间隔不足，还需 {$remaining} 小时");
            return;
        }

        // 6. 可以进入下一天
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
        $dailyLimit   = $dailyAmounts[$currentDay - 1] ?? 0;

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
                $dailyAmount                 = ItunesTradeAccountLog::where('account_id', $account->id)
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
            'status'           => ItunesTradeAccount::STATUS_COMPLETED,
            'current_plan_day' => null,
            'plan_id'          => null,
            'completed_days'   => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        // 通过队列请求登出
        ProcessAppleAccountLogoutJob::dispatch($account->id, 'plan_completed');

        // 发送完成通知
        $msg = "[强]兑换目标达成通知\n";
        $msg .= "---------------------------------\n";
        $msg .= $account->account . "\n";
        $msg .= "国家：{$account->country_code}   账户余款：{$currentTotalAmount}";

        try {
            send_msg_to_wechat('45958721463@chatroom', $msg);
        } catch (\Exception $e) {
            $this->getLogger()->error("发送微信通知失败: " . $e->getMessage());
        }

        $this->getLogger()->info('账号计划完成', [
            'account'        => $account->account,
            'total_amount'   => $currentTotalAmount,
            'completed_days' => $completedDays
        ]);
    }

    /**
     * 进入下一天
     */
    private function advanceToNextDay(ItunesTradeAccount $account): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $nextDay    = $currentDay + 1;

        // 更新completed_days
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
        for ($day = 1; $day <= $account->plan->plan_days; $day++) {
            $dailyAmount                 = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $day)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');
            $completedDays[(string)$day] = $dailyAmount;
        }

        $account->timestamps = false;
        $account->update([
            'current_plan_day' => $nextDay,
            'status'           => ItunesTradeAccount::STATUS_PROCESSING,
            'completed_days'   => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        // 通过队列请求登录
        ProcessAppleAccountLoginJob::dispatch($account->id, 'advance_to_next_day');

        $this->getLogger()->info('账号进入下一天', [
            'account'        => $account->account,
            'current_day'    => $nextDay,
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
                $dailyAmount                 = ItunesTradeAccountLog::where('account_id', $account->id)
                    ->where('day', $day)
                    ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                    ->sum('amount');
                $completedDays[(string)$day] = $dailyAmount;
            }
        }

        $account->timestamps = false;
        $account->update([
            'plan_id'          => null,
            'current_plan_day' => null,
            'status'           => ItunesTradeAccount::STATUS_WAITING,
            'completed_days'   => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        // 通过队列请求登出
        ProcessAppleAccountLogoutJob::dispatch($account->id, 'plan_timeout_unbound');

        $this->getLogger()->info('账号计划解绑', [
            'account'        => $account->account,
            'reason'         => '最后一天超时未完成',
            'completed_days' => $completedDays
        ]);
    }

    private function getLogger(): LoggerInterface
    {
        return Log::channel('kernel_process_accounts');
    }
}
