<?php

namespace App\Console\Commands;

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
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

            $this->getLogger()->info("找到 {$accounts->count()} 个需要处理的账号");

            foreach ($accounts as $account) {
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

        // 2. 根据状态处理
        if ($account->status === ItunesTradeAccount::STATUS_LOCKING) {
            $this->processLockingAccount($account);
        } elseif ($account->status === ItunesTradeAccount::STATUS_WAITING) {
            $this->processWaitingAccount($account);
        }
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
            $this->getLogger()->info("账号 {$account->account} 没有成功的兑换记录，保持锁定状态");
            return;
        }

        // 更新completed_days字段
        $this->updateCompletedDays($account, $lastSuccessLog);

        // 检查账号总金额是否达到计划金额
        if ($this->isAccountCompleted($account)) {
            $this->markAccountCompleted($account);
            return;
        }

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
            $this->getLogger()->warning("账号 {$account->account} 没有关联的计划，跳过处理");
            return;
        }

        // 获取最后一条成功日志
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        if (!$lastSuccessLog) {
            $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
            $this->getLogger()->info("账号 {$account->account} 没有成功的兑换记录，恢复待执行状态");
            return;
        }

        $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
        $now = now();

        // 计算时间间隔（分钟）
        $intervalMinutes = $lastExchangeTime->diffInMinutes($now);
        $requiredExchangeInterval = $account->plan->exchange_interval ?? 5;

        $this->getLogger()->info("账号 {$account->account} 时间检查: 间隔 {$intervalMinutes} 分钟, 兑换间隔要求 {$requiredExchangeInterval} 分钟");

        // 检查是否满足兑换间隔时间
        if ($intervalMinutes < $requiredExchangeInterval) {
            $this->getLogger()->info("账号 {$account->account} 兑换间隔时间不足，保持等待状态");
            return;
        }

        // 满足兑换间隔，检查日期间隔
        $intervalHours = $lastExchangeTime->diffInHours($now);
        $requiredDayInterval = $account->plan->day_interval ?? 24;

        $this->getLogger()->info("账号 {$account->account} 日期检查: 间隔 {$intervalHours} 小时, 日期间隔要求 {$requiredDayInterval} 小时");

        if ($intervalHours >= $requiredDayInterval) {
            // 超过日期间隔，进入下一天
            $this->advanceToNextDay($account);
        } else {
            // 未超过日期间隔，检查当日计划是否完成
            $this->checkDailyPlanCompletion($account);
        }
    }

    /**
     * 更新completed_days字段
     */
    private function updateCompletedDays(ItunesTradeAccount $account, ItunesTradeAccountLog $lastSuccessLog): void
    {
        $currentDay = $account->current_plan_day ?? 1;

        // 计算当天累计兑换金额
        $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        // 更新completed_days字段
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
        $completedDays[(string)$currentDay] = $dailyAmount;

        $account->update(['completed_days' => json_encode($completedDays)]);

        $this->getLogger()->info("更新账号 {$account->account} 第{$currentDay}天完成情况: {$dailyAmount}");
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
        // 更新completed_days字段
        $currentDay = $account->current_plan_day ?? 1;
        $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
        $completedDays[(string)$currentDay] = $dailyAmount;

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
        $msg .= $account->account;

        send_msg_to_wechat('44769140035@chatroom', $msg);
    }

    /**
     * 进入下一天
     */
    private function advanceToNextDay(ItunesTradeAccount $account): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $nextDay = $currentDay + 1;

        // 更新completed_days字段
        $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
        $completedDays[(string)$currentDay] = $dailyAmount;

        $account->update([
            'current_plan_day' => $nextDay,
            'status' => ItunesTradeAccount::STATUS_PROCESSING,
            'completed_days' => json_encode($completedDays),
        ]);

        $this->getLogger()->info('账号进入下一天', [
            'account_id' => $account->account,
            'account' => $account->account,
            'current_day' => $nextDay,
            'status_changed' => 'WAITING -> PROCESSING',
            'reason' => '超过日期间隔，进入下一天'
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

        if ($dailyAmount >= $dailyLimit) {
            $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天计划已完成，保持等待状态");
        } else {
            // 计划未完成，状态改为processing
            $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);

            $this->getLogger()->info('等待账号状态转换为执行中', [
                'account_id' => $account->account,
                'account' => $account->account,
                'current_day' => $currentDay,
                'status_changed' => 'WAITING -> PROCESSING',
                'reason' => '当日计划未完成，转为执行状态'
            ]);
        }
    }
}
