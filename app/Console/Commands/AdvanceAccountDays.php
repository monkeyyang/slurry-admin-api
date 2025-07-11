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
 * iTunes账号状态处理命令
 *
 * 职责：
 * 1. 处理WAITING状态账号，检查总额完成情况
 * 2. 总额完成则标记为COMPLETED，未完成则转为PROCESSING状态
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
    protected $description = 'iTunes账号状态处理 - 检查WAITING状态账号的总额完成情况并转换状态（30分钟调度控制间隔）';

    private bool $dryRun;

    /**
     * 执行控制台命令
     */
    public function handle(): void
    {
        $this->dryRun = $this->option('dry-run');
        $date         = now();

        $this->getLogger()->info("========== iTunes账号状态处理开始 [{$date}] ==========");

        if ($this->dryRun) {
            $this->info("🔍 DRY RUN 模式：只显示操作，不实际执行");
        }

        try {
            // 处理 WAITING 状态账号
            $this->processWaitingAccounts();

            $this->getLogger()->info('iTunes账号状态处理完成');

        } catch (\Exception $e) {
            $this->getLogger()->error('状态处理过程中发生错误: ' . $e->getMessage());
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
        $this->info("处理WAITING账号: {$account->account}");
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
                    // ProcessAppleAccountLoginJob::dispatch($account->id, 'no_plan_with_balance');
                }
            } else {
                $this->getLogger()->debug("无计划零余额账号保持等待: {$account->account}");
            }
            return;
        }

        // 3. 检查是否有兑换记录
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        if (!$lastSuccessLog) {
            $this->info("🚀 新账号开始: {$account->account} -> PROCESSING");

            if (!$this->dryRun) {
                $account->timestamps = false;
                $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
                $account->timestamps = true;

                // 通过队列请求登录
                // ProcessAppleAccountLoginJob::dispatch($account->id, 'new_account_start');
            }
            return;
        }

        // 4. 检查天数间隔（不再检查当日计划完成）
        $lastExchangeTime    = Carbon::parse($lastSuccessLog->exchange_time);
        $now                 = now();
        $intervalHours       = $lastExchangeTime->diffInHours($now);
        $requiredDayInterval = max(1, $account->plan->day_interval ?? 24);

        if ($intervalHours < $requiredDayInterval) {

            $remaining = $requiredDayInterval - $intervalHours;
            $this->info("账号 {$account->account} 天数间隔不足，还需 {$remaining} 小时");
            $this->getLogger()->debug("账号 {$account->account} 天数间隔不足，还需 {$remaining} 小时");
            return;
        }

        // 5. 天数间隔已满足，总额未完成，转为 PROCESSING
        $this->info("⚡ 账号继续处理: {$account->account} -> PROCESSING (天数间隔已满足)");

        if (!$this->dryRun) {
            $account->timestamps = false;
            $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
            $account->timestamps = true;

            // 通过队列请求登录
            // ProcessAppleAccountLoginJob::dispatch($account->id, 'interval_satisfied');
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

    private function getLogger(): LoggerInterface
    {
        return Log::channel('kernel_process_accounts');
    }
}
