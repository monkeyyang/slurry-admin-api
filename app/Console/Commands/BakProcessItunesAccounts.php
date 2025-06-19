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

class BakProcessItunesAccounts extends Command
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

            // 1. 处理锁定状态的账号
            $this->processLockingAccounts();

            // 2. 处理等待状态的账号
            $this->processWaitingAccounts();

            // 3. 检查并更新天数完成情况
            $this->checkDayCompletion();

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
     * 获取礼品卡兑换专用日志实例
     */
    private function getLogger(): LoggerInterface
    {
        return Log::channel('kernel_process_accounts');
    }

    /**
     * 处理锁定状态的账号
     */
    private function processLockingAccounts(): void
    {
        $this->getLogger()->info('处理锁定状态的账号...');

        $lockingAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_LOCKING)
            ->with('plan')
            ->get();

        $this->getLogger()->info("找到 {$lockingAccounts->count()} 个锁定状态的账号");

        foreach ($lockingAccounts as $account) {
            try {
                $this->processLockingAccount($account);
            } catch (\Exception $e) {
                $this->getLogger()->error("处理锁定账号 {$account->account} 失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 处理单个锁定状态的账号
     */
    private function processLockingAccount(ItunesTradeAccount $account): void
    {
        // 获取最后一次兑换成功的时间
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        if (!$lastSuccessLog) {
            // 强制刷新登录
            $loginAccount = [
                'id' => $account->id,
                'username' => $account->account,
                'password' => $account->getDecryptedPassword(),
                'verifyUrl' => $account->api_url
            ];
            try {
                // 发送强制登录请求
                $this->giftCardApiClient->refreshUserLogin($loginAccount);
                // 更新状态为Waiting，当前天为1
                $account->update(['status' => ItunesTradeAccount::STATUS_WAITING, 'current_plan_day' => 1]);
                $this->getLogger()->warning("账号 {$account->account} 没有成功的兑换记录，发送强制登录请求并登录成功，直接设置为等待状态Waiting");
            } catch (\Exception $e) {
                $account->update(['status'=> ItunesTradeAccount::STATUS_WAITING, 'login_status'=> ItunesTradeAccount::STATUS_LOGIN_FAILED]);
                $this->getLogger()->warning("账号 {$account->account} 没有成功的兑换记录，发送强制登录请求并登录失败，直接设置为等待状态Waiting");
            }
            return;
        }

        // 计算时间间隔（分钟）
        $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
        $intervalMinutes = $lastExchangeTime->diffInMinutes(now());

        // 获取计划要求的兑换间隔（分钟）, 如果没有计划默认5分钟
        if(!$account->plan) {
            $requiredInterval = 5;
        } else {
            $requiredInterval = $account->plan->exchange_interval ?? 0;
        }

        $this->getLogger()->info("账号 {$account->id}: 间隔 {$intervalMinutes} 分钟, 要求 {$requiredInterval} 分钟");
        if ($intervalMinutes >= $requiredInterval) {
            // 间隔时间已够，将状态改为waiting
            DB::transaction(function () use ($account) {
                $account->update(['status' => ItunesTradeAccount::STATUS_WAITING]);

                $this->getLogger()->info('锁定账号状态转换为等待', [
                    'account_id' => $account->account,
                    'account' => $account->account,
                    'status_changed' => 'LOCKING -> WAITING',
                    'reason' => '兑换间隔时间已满足要求'
                ]);
            });

            $this->getLogger()->info("账号 {$account->account} 状态已从 LOCKING 转换为 WAITING");
        } else {
            $remainingMinutes = $requiredInterval - $intervalMinutes;
            $this->getLogger()->info("账号 {$account->account} 还需等待 {$remainingMinutes} 分钟");
        }
    }

    /**
     * 处理等待状态的账号
     */
    private function processWaitingAccounts(): void
    {
        $this->getLogger()->info('处理等待状态的账号...');

        $waitingAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_WAITING)
            ->with('plan')
            ->get();

        $this->getLogger()->info("找到 {$waitingAccounts->count()} 个等待状态的账号");

        foreach ($waitingAccounts as $account) {
            try {
                $this->processWaitingAccount($account);
            } catch (\Exception $e) {
                $this->getLogger()->error("处理等待账号 {$account->account} 失败: " . $e->getMessage());
                $this->getLogger()->error('处理等待账号失败', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 处理单个等待状态的账号
     */
    private function processWaitingAccount(ItunesTradeAccount $account): void
    {
        if (!$account->plan) {
            $this->getLogger()->warning("账号 {$account->account} 没有关联的计划，跳过处理");
            return;
        }

        $currentDay = $account->current_plan_day ?? 1;

        // 获取当前天的最后一次成功兑换时间
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        if (!$lastSuccessLog) {
            $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天还没有成功兑换记录");
            return;
        }

        // 计算时间间隔（小时）
        $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
        $intervalHours = $lastExchangeTime->diffInHours(now());

        // 获取计划要求的天间隔（小时）
        $requiredDayInterval = $account->plan->day_interval ?? 24;

        $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天: 间隔 {$intervalHours} 小时, 要求 {$requiredDayInterval} 小时");

        if ($intervalHours >= $requiredDayInterval) {
            // 检查当天是否达到目标额度
            $this->checkAndAdvanceDay($account);
        } else {
            $remainingHours = $requiredDayInterval - $intervalHours;
            $this->getLogger()->info("账号 {$account->account} 还需等待 {$remainingHours} 小时才能进入下一天");
        }
    }

    /**
     * 检查并推进到下一天
     */
    private function checkAndAdvanceDay(ItunesTradeAccount $account): void
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

        $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天: 已兑换 {$dailyAmount}, 目标 {$dailyLimit}");

        if ($dailyAmount >= $dailyLimit) {
            // 达到目标，可以进入下一天
            DB::transaction(function () use ($account, $plan, $currentDay, $dailyAmount) {
                // 更新完成天数记录
                $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
                $completedDays[(string)$currentDay] = $dailyAmount;

                if ($currentDay >= $plan->plan_days || $account->amount >= $plan->total_amount) {
                    // 计划完成
                    $account->update([
                        'status' => ItunesTradeAccount::STATUS_COMPLETED,
                        'current_plan_day' => null,
                        'plan_id' => null,
                        'completed_days' => json_encode($completedDays),
                    ]);

                    $this->getLogger()->info('账号计划完成', [
                        'account_id' => $account->account,
                        'account' => $account->account,
                        'plan_id' => $plan->id,
                        'total_completed_days' => count($completedDays),
                        'final_completed_days' => $completedDays
                    ]);

                    // 删除登录态
                    $loginAccount = [
                        'id' => $account->id,
                        'username'=> $account->account,
                        'password' => $account->getDecryptedPassword(),
                        'verify_url' => $account->api_url
                    ];
                    $this->giftCardApiClient->deleteUserLogins($loginAccount);
                    // 发送完成通知
                    $msg = "[强]兑换目标达成通知\n";
                    $msg .= "---------------\n";
                    $msg .= $account->account;

                    send_msg_to_wechat('44769140035@chatroom', $msg);
                    $this->getLogger()->info("账号 {$account->account} 计划已完成");
                } else {
                    // 进入下一天, 状态改为执行中
                    $nextDay = $currentDay + 1;
                    $account->update([
                        'status' => ItunesTradeAccount::STATUS_PROCESSING,
                        'current_plan_day' => $nextDay,
                        'completed_days' => json_encode($completedDays),
                    ]);

                    $this->getLogger()->info('账号进入下一天', [
                        'account_id' => $account->id,
                        'account' => $account->account,
                        'current_day' => $nextDay,
                        'plan_id' => $plan->id,
                        'completed_days' => $completedDays
                    ]);

                    $this->getLogger()->info("账号 {$account->account} 已进入第{$nextDay}天");
                }
            });
        } else {
            $remainingAmount = $dailyLimit - $dailyAmount;
            $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天目标未达成，还需兑换 {$remainingAmount}, 状态改为PROCESSING");

            // 更新完成天数记录，但不推进天数
            $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
            $completedDays[(string)$currentDay] = $dailyAmount;
            $account->update([
                'status' => ItunesTradeAccount::STATUS_PROCESSING,
                'completed_days' => json_encode($completedDays)
            ]);
        }
    }

    /**
     * 检查天数完成情况（处理之前注释掉的逻辑）
     */
    private function checkDayCompletion(): void
    {
        $this->getLogger()->info('检查天数完成情况...');

        // 查找所有状态为LOCKING且有待处理任务完成的账号
        $accountsToCheck = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_LOCKING)
            ->whereNotNull('plan_id')
            ->whereNotNull('current_plan_day')
            ->with('plan')
            ->get();

        foreach ($accountsToCheck as $account) {
            try {
                $this->checkAccountDayCompletion($account);
            } catch (\Exception $e) {
                $this->getLogger()->error('检查账号天数完成情况失败', [
                    'account_id' => $account->account,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 检查单个账号的天数完成情况
     */
    private function checkAccountDayCompletion(ItunesTradeAccount $account): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $plan = $account->plan;

        // 检查当天是否还有待处理的任务
        $pendingCount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_PENDING)
            ->count();

        if ($pendingCount > 0) {
            $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天还有 {$pendingCount} 个待处理任务");
            return;
        }

        // 当天任务全部完成，计算当天的累计兑换金额
        $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        // 获取当天的计划额度
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;

        $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天任务完成检查: 已兑换 {$dailyAmount}, 目标 {$dailyLimit}");

        // 更新completed_days字段
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
        $completedDays[(string)$currentDay] = $dailyAmount;

        // 检查是否达到当天的目标额度
        if ($dailyAmount >= $dailyLimit) {
            // 达到目标额度，可以立即进入下一天或完成计划
            DB::transaction(function () use ($account, $plan, $currentDay, $completedDays) {
                if ($currentDay >= $plan->plan_days) {
                    // 计划完成
                    $account->update([
                        'status' => ItunesTradeAccount::STATUS_COMPLETED,
                        'current_plan_day' => null,
                        'plan_id' => null,
                        'completed_days' => json_encode($completedDays),
                    ]);

                    $this->getLogger()->info('账号计划立即完成', [
                        'account_id' => $account->account,
                        'account' => $account->account,
                        'plan_id' => $plan->id,
                        'reason' => '当天目标达成且为最后一天'
                    ]);

                    // 删除登录态
                    $loginAccount = [
                        'id' => $account->id,
                        'username'=> $account->account,
                        'password' => $account->getDecryptedPassword(),
                        'verify_url' => $account->api_url
                    ];
                    $this->giftCardApiClient->deleteUserLogins($loginAccount);
                    // 发送完成通知
                    $msg = "[强]兑换目标达成通知\n";
                    $msg .= "---------------\n";
                    $msg .= $account->account;

                    send_msg_to_wechat('44769140035@chatroom', $msg);
                } else {
                    // 立即进入下一天
                    $nextDay = $currentDay + 1;
                    $account->update([
                        'status' => ItunesTradeAccount::STATUS_WAITING,
                        'current_plan_day' => $nextDay,
                        'completed_days' => json_encode($completedDays),
                    ]);

                    $this->getLogger()->info('账号立即进入下一天', [
                        'account_id' => $account->account,
                        'account' => $account->account,
                        'current_day' => $nextDay,
                        'plan_id' => $plan->id,
                        'reason' => '当天目标达成'
                    ]);
                }
            });

            $this->getLogger()->info("账号 {$account->account} 当天目标达成，状态已更新");
        } else {
            // 未达到目标额度，保持锁定状态，等待更多兑换或时间间隔
            $account->update(['completed_days' => json_encode($completedDays)]);

            $remainingAmount = $dailyLimit - $dailyAmount;
            $this->getLogger()->info("账号 {$account->account} 当天目标未达成，还需兑换 {$remainingAmount}，保持锁定状态");
        }
    }
}
