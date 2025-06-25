<?php

namespace App\Console\Commands;

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use App\Services\GiftCardApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Carbon\Carbon;

class FixAccountDataInconsistency extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:account-data-inconsistency {--dry-run : 只检查不修复}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '修复iTunes账号数据不一致问题';

    protected GiftCardApiClient $giftCardApiClient;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('=== 数据一致性检查（模拟模式）===');
        } else {
            $this->info('=== 数据一致性修复 ===');
        }

        try {
            // 1. 检查current_plan_day超过plan_days的账号
            $this->checkCurrentDayExceedsPlanDays($isDryRun);

            // 2. 检查没有计划但有计划相关字段的账号
            $this->checkAccountsWithoutPlan($isDryRun);

            // 3. 检查缺少天数记录的账号
            $this->checkMissingDayRecords($isDryRun);

            // 4. 检查可能无限等待的账号
            $this->checkInfiniteWaitingAccounts($isDryRun);

            // 5. 检查current_plan_day与实际执行进度不一致的账号
            $this->checkCurrentDayInconsistency($isDryRun);

            $this->info('检查完成！');

        } catch (\Exception $e) {
            $this->error('处理过程中发生错误: ' . $e->getMessage());
            $this->getLogger()->error('数据一致性检查失败', [
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
     * 检查current_plan_day超过plan_days的账号
     */
    private function checkCurrentDayExceedsPlanDays(bool $isDryRun): void
    {
        $this->info('');
        $this->info('1. 检查current_plan_day超过plan_days的账号...');

        // 查找所有有问题的账号
        $problematicAccounts = $this->findProblematicAccounts();

        if ($problematicAccounts->isEmpty()) {
            $this->info('未发现数据不一致问题');
            return;
        }

        $this->getLogger()->warning("发现 {$problematicAccounts->count()} 个账号存在数据不一致问题");

        foreach ($problematicAccounts as $account) {
            $this->processProblematicAccount($account, $isDryRun);
        }

        if ($isDryRun) {
            $this->info('');
            $this->info('以上是模拟结果，要实际修复请运行: php artisan fix:account-data-inconsistency');
        }
    }

    /**
     * 检查没有计划但有计划相关字段的账号
     */
    private function checkAccountsWithoutPlan(bool $isDryRun): void
    {
//        $this->info('');
//        $this->info('2. 检查没有计划但有计划相关字段的账号...');
//
//        // 查找所有没有计划但有计划相关字段的账号
//        $accountsWithoutPlan = ItunesTradeAccount::whereNull('plan_id')
//            ->with('plan')
//            ->get()
//            ->filter(function ($account) {
//                if ($account->plan) {
//                    return false; // 有计划的账号
//                }
//                return true; // 没有计划的账号
//            });
////        var_dump($accountsWithoutPlan->toArray());exit;
//        if ($accountsWithoutPlan->isEmpty()) {
//            $this->info('未发现没有计划但有计划相关字段的账号');
//            return;
//        }
//
//        $this->getLogger()->warning("发现 {$accountsWithoutPlan->count()} 个账号没有计划但有计划相关字段");
//
//        foreach ($accountsWithoutPlan as $account) {
//            $this->processAccountsWithoutPlan($account, $isDryRun);
//        }
//
//        if ($isDryRun) {
//            $this->info('');
//            $this->info('以上是模拟结果，要实际修复请运行: php artisan fix:account-data-inconsistency');
//        }
    }

    /**
     * 检查缺少天数记录的账号
     */
    private function checkMissingDayRecords(bool $isDryRun): void
    {
        $this->info('');
        $this->info('3. 检查缺少天数记录的账号...');

        // 1. 查找所有已完成的账号，检查是否有缺失的天数记录
        $completedAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_COMPLETED)
            ->whereNotNull('plan_id')
            ->with(['plan', 'exchangeLogs' => function($query) {
                $query->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                      ->orderBy('day', 'asc')
                      ->orderBy('exchange_time', 'desc');
            }])
            ->get();

        // 2. 查找等待状态但没有成功记录的账号
        $waitingAccountsWithoutLogs = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_WAITING)
            ->whereNotNull('plan_id')
            ->with(['plan'])
            ->whereDoesntHave('exchangeLogs', function($query) {
                $query->where('status', ItunesTradeAccountLog::STATUS_SUCCESS);
            })
            ->get();

        $accountsWithMissingDays = [];

        // 处理已完成但缺少天数记录的账号
        foreach ($completedAccounts as $account) {
            if (!$account->plan) {
                continue;
            }

            // 获取该账号所有成功的兑换天数
            $successfulDays = $account->exchangeLogs->pluck('day')->unique()->sort()->values()->toArray();

            // 检查是否有缺失的天数
            $expectedDays = range(1, $account->plan->plan_days);
            $missingDays = array_diff($expectedDays, $successfulDays);

            if (!empty($missingDays)) {
                // 获取最后一次成功兑换的时间
                $lastSuccessLog = $account->exchangeLogs
                    ->sortByDesc('exchange_time')
                    ->first();
                $lastExchangeTime = $lastSuccessLog ? Carbon::parse($lastSuccessLog->exchange_time) : null;

                // 计算距离现在的时间间隔
                $hoursFromLastExchange = $lastExchangeTime ? $lastExchangeTime->diffInHours(now()) : 0;
                $requiredDayInterval = $account->plan->day_interval ?? 24;

                // 判断应该设置的状态
                $shouldBeWaiting = $lastExchangeTime && ($hoursFromLastExchange < $requiredDayInterval);

                $accountsWithMissingDays[] = [
                    'account' => $account,
                    'type' => 'completed_with_missing_days',
                    'expected_days' => $expectedDays,
                    'successful_days' => $successfulDays,
                    'missing_days' => $missingDays,
                    'plan_days' => $account->plan->plan_days,
                    'last_exchange_time' => $lastExchangeTime,
                    'hours_from_last' => $hoursFromLastExchange,
                    'required_interval' => $requiredDayInterval,
                    'should_be_waiting' => $shouldBeWaiting
                ];
            }
        }

        // 处理等待状态但没有成功记录的账号
        foreach ($waitingAccountsWithoutLogs as $account) {
            if (!$account->plan) {
                continue;
            }

            $accountsWithMissingDays[] = [
                'account' => $account,
                'type' => 'waiting_without_logs',
                'expected_days' => range(1, $account->plan->plan_days),
                'successful_days' => [],
                'missing_days' => range(1, $account->plan->plan_days),
                'plan_days' => $account->plan->plan_days,
                'last_exchange_time' => null,
                'hours_from_last' => 0,
                'required_interval' => $account->plan->day_interval ?? 24,
                'should_be_waiting' => false // 没有记录的应该开始处理
            ];
        }

        if (empty($accountsWithMissingDays)) {
            $this->info('✓ 没有发现缺少天数记录的账号');
            return;
        }

        $this->warn("发现 " . count($accountsWithMissingDays) . " 个账号需要处理:");

        foreach ($accountsWithMissingDays as $item) {
            $account = $item['account'];
            $missingDays = $item['missing_days'];

            $this->warn("- 账号: {$account->account} (ID: {$account->id})");
            $this->warn("  类型: " . ($item['type'] === 'waiting_without_logs' ? '等待状态无记录' : '已完成缺少天数'));
            $this->warn("  计划天数: {$item['plan_days']}");
            $this->warn("  已有记录天数: " . (empty($item['successful_days']) ? '无' : implode(', ', $item['successful_days'])));
            $this->warn("  缺失天数: " . implode(', ', $missingDays));

            if ($item['type'] === 'waiting_without_logs') {
                $this->warn("  建议状态: PROCESSING (开始第1天)");
            } else {
                $this->warn("  最后兑换时间: " . ($item['last_exchange_time'] ? $item['last_exchange_time']->format('Y-m-d H:i:s') : '无'));
                $this->warn("  距离现在: {$item['hours_from_last']} 小时");
                $this->warn("  要求间隔: {$item['required_interval']} 小时");

                if ($item['should_be_waiting']) {
                    $this->warn("  建议状态: WAITING (未满足日期间隔)");
                } else {
                    $this->warn("  建议状态: PROCESSING (可以进入下一天)");
                }
            }

            if (!$isDryRun) {
                $this->processMissingDayRecords($account, $missingDays, $item['should_be_waiting'], $item['type']);
            }
        }

        if ($isDryRun) {
            $this->info('');
            $this->info('以上是模拟结果，要实际修复请运行: php artisan fix:account-data-inconsistency');
        }
    }

    /**
     * 检查可能无限等待的账号
     */
    private function checkInfiniteWaitingAccounts(bool $isDryRun): void
    {
        $this->info('');
        $this->info('4. 检查可能无限等待的账号...');

        // 查找等待状态超过72小时的账号
        $waitingAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_WAITING)
            ->whereNotNull('plan_id')
            ->with(['plan', 'exchangeLogs' => function($query) {
                $query->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                      ->orderBy('exchange_time', 'desc')
                      ->limit(1);
            }])
            ->get();

        $suspiciousAccounts = [];
        $now = now();

        foreach ($waitingAccounts as $account) {
            $lastSuccessLog = $account->exchangeLogs->first();

            if (!$lastSuccessLog) {
                // 没有成功记录但处于等待状态超过1小时
                if ($account->updated_at->diffInHours($now) >= 1) {
                    $suspiciousAccounts[] = [
                        'account' => $account,
                        'issue' => '没有成功兑换记录但长时间处于等待状态',
                        'waiting_hours' => $account->updated_at->diffInHours($now),
                        'action' => '转为PROCESSING状态'
                    ];
                }
                continue;
            }

            $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
            $waitingHours = $lastExchangeTime->diffInHours($now);

            // 检查是否等待时间过长
            $maxReasonableWaitingHours = ($account->plan->day_interval ?? 24) * 2;
            if ($waitingHours >= $maxReasonableWaitingHours) {
                $suspiciousAccounts[] = [
                    'account' => $account,
                    'issue' => '等待时间过长',
                    'waiting_hours' => $waitingHours,
                    'max_reasonable_hours' => $maxReasonableWaitingHours,
                    'action' => '标记为完成'
                ];
            }

            // 检查计划配置问题
            if (!$this->validatePlanConfiguration($account->plan)) {
                $suspiciousAccounts[] = [
                    'account' => $account,
                    'issue' => '计划配置无效',
                    'waiting_hours' => $waitingHours,
                    'action' => '标记为完成'
                ];
            }
        }

        if (empty($suspiciousAccounts)) {
            $this->info('✓ 没有发现可能无限等待的账号');
            return;
        }

        $this->warn("发现 " . count($suspiciousAccounts) . " 个可能无限等待的账号:");

        foreach ($suspiciousAccounts as $item) {
            $account = $item['account'];
            $this->warn("- 账号: {$account->account} (ID: {$account->id})");
            $this->warn("  问题: {$item['issue']}");
            $this->warn("  等待时间: {$item['waiting_hours']} 小时");
            $this->warn("  建议操作: {$item['action']}");

            if (!$isDryRun) {
                try {
                    if ($item['action'] === '转为PROCESSING状态') {
                        $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
                        $this->info("  ✓ 已将账号 {$account->account} 转为PROCESSING状态");

                        $this->getLogger()->info("修复无限等待账号", [
                            'account_id' => $account->id,
                            'account' => $account->account,
                            'old_status' => 'WAITING',
                            'new_status' => 'PROCESSING',
                            'issue' => $item['issue']
                        ]);
                    } else {
                        // 标记为完成
                        $account->update([
                            'status' => ItunesTradeAccount::STATUS_COMPLETED,
                            'current_plan_day' => null,
                            'plan_id' => null,
                        ]);
                        $this->info("  ✓ 已将账号 {$account->account} 标记为完成");

                        $this->getLogger()->info("修复无限等待账号", [
                            'account_id' => $account->id,
                            'account' => $account->account,
                            'old_status' => 'WAITING',
                            'new_status' => 'COMPLETED',
                            'issue' => $item['issue']
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->error("  ✗ 修复账号 {$account->account} 失败: " . $e->getMessage());
                }
            }
        }

        if ($isDryRun) {
            $this->info('');
            $this->info('以上是模拟结果，要实际修复请运行: php artisan fix:account-data-inconsistency');
        }
    }

    /**
     * 验证计划配置的完整性
     */
    private function validatePlanConfiguration($plan): bool
    {
        if (!$plan) {
            return false;
        }

        // 检查基本配置
        if (empty($plan->plan_days) || $plan->plan_days <= 0) {
            return false;
        }

        if (empty($plan->total_amount) || $plan->total_amount <= 0) {
            return false;
        }

        // 检查daily_amounts配置
        $dailyAmounts = $plan->daily_amounts ?? [];
        if (empty($dailyAmounts) || !is_array($dailyAmounts)) {
            return false;
        }

        if (count($dailyAmounts) != $plan->plan_days) {
            return false;
        }

        // 检查每日金额是否合理
        foreach ($dailyAmounts as $amount) {
            if (!is_numeric($amount) || $amount < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * 查找有问题的账号
     */
    private function findProblematicAccounts()
    {
        return ItunesTradeAccount::whereNotNull('plan_id')
            ->whereNotNull('current_plan_day')
            ->with('plan')
            ->get()
            ->filter(function ($account) {
                if (!$account->plan) {
                    return true; // 计划被删除的账号
                }

                $currentDay = $account->current_plan_day ?? 1;
                $planDays = $account->plan->plan_days;

                return $currentDay > $planDays; // 当前天数超过计划天数
            });
    }

    /**
     * 处理有问题的账号
     */
    private function processProblematicAccount(ItunesTradeAccount $account, bool $isDryRun): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $planDays = $account->plan ? $account->plan->plan_days : 0;

        $this->line("处理账号: {$account->account}");
        $this->line("  当前天数: {$currentDay}");
        $this->line("  计划天数: {$planDays}");

        if (!$account->plan) {
            $this->warn("  问题: 计划已被删除");
            if (!$isDryRun) {
                $account->update([
                    'plan_id' => null,
                    'current_plan_day' => null,
                    'status' => ItunesTradeAccount::STATUS_WAITING
                ]);
                $this->info("  修复: 清除计划关联，状态设为等待");
            }
            return;
        }

        if ($currentDay > $planDays) {
            $this->warn("  问题: 当前天数超过计划天数");

            // 检查账号是否应该完成
            if ($this->shouldAccountBeCompleted($account)) {
                $this->info("  建议: 标记为完成");
                if (!$isDryRun) {
                    $this->markAccountCompleted($account);
                    $this->info("  修复: 已标记为完成");
                }
            } else {
                $this->info("  建议: 重置当前天数为 {$planDays}");
                if (!$isDryRun) {
                    $account->update(['current_plan_day' => $planDays]);
                    $this->info("  修复: 当前天数已重置为 {$planDays}");
                }
            }
        }

        $this->line('');
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
     * 标记账号为完成状态
     */
    private function markAccountCompleted(ItunesTradeAccount $account): void
    {
        if (!$account->plan) {
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

        $this->getLogger()->info('账号计划完成（数据修复）', [
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
        $msg = "[强]兑换目标达成通知（数据修复）\n";
        $msg .= "---------------\n";
        $msg .= $account->account;

        send_msg_to_wechat('44769140035@chatroom', $msg);
    }

    /**
     * 处理没有计划的账号
     */
    private function processAccountsWithoutPlan(ItunesTradeAccount $account, bool $isDryRun): void
    {
        $this->line("处理账号: {$account->account}");
        $this->line("  问题: 没有计划但有计划相关字段");

        if (!$isDryRun) {
            $account->update([
                'plan_id' => null,
                'current_plan_day' => null,
                'status' => ItunesTradeAccount::STATUS_WAITING
            ]);
            $this->info("  修复: 清除计划关联，状态设为等待");
        }
    }

    /**
     * 处理缺少天数记录的账号
     */
    private function processMissingDayRecords(ItunesTradeAccount $account, array $missingDays, bool $shouldBeWaiting, string $type): void
    {
        try {
            $this->giftCardApiClient = app(GiftCardApiClient::class);

            // 1. 重新登录账号
            $this->info("  正在重新登录账号...");
            $loginResult = $this->reloginAccount($account);

            if (!$loginResult['success']) {
                $this->error("  ✗ 账号登录失败: " . $loginResult['message']);
                return;
            }

            $this->info("  ✓ 账号登录成功");

            // 2. 设置账号状态和天数
            if ($type === 'waiting_without_logs') {
                // 等待状态无记录：设为第1天，处理中
                $targetDay = 1;
                $targetStatus = ItunesTradeAccount::STATUS_PROCESSING;
                $statusDescription = 'WAITING -> PROCESSING (开始第1天)';
            } else {
                // 已完成缺少天数：根据时间间隔判断
                $firstMissingDay = min($missingDays);
                $targetDay = $firstMissingDay;
                $targetStatus = $shouldBeWaiting ? ItunesTradeAccount::STATUS_WAITING : ItunesTradeAccount::STATUS_PROCESSING;
                $statusDescription = 'COMPLETED -> ' . ($shouldBeWaiting ? 'WAITING' : 'PROCESSING');
            }

            // 更新账号状态和当前天数
            $account->update([
                'status' => $targetStatus,
                'current_plan_day' => $targetDay,
                'login_status' => ItunesTradeAccount::STATUS_LOGIN_ACTIVE
            ]);

            $this->info("  ✓ 账号状态已更新");
            $this->info("    - 状态: {$statusDescription}");
            $this->info("    - 当前天数: {$targetDay}");
            $this->info("    - 登录状态: ACTIVE");

            // 3. 记录日志
            $this->getLogger()->info("修复缺失天数记录的账号", [
                'account_id' => $account->id,
                'account' => $account->account,
                'type' => $type,
                'missing_days' => $missingDays,
                'reset_to_day' => $targetDay,
                'old_status' => $type === 'waiting_without_logs' ? 'WAITING' : 'COMPLETED',
                'new_status' => $targetStatus === ItunesTradeAccount::STATUS_WAITING ? 'WAITING' : 'PROCESSING'
            ]);

        } catch (\Exception $e) {
            $this->error("  ✗ 处理账号失败: " . $e->getMessage());
            $this->getLogger()->error("修复缺失天数记录失败", [
                'account_id' => $account->id,
                'account' => $account->account,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 检查current_plan_day与实际执行进度不一致的账号
     */
    private function checkCurrentDayInconsistency(bool $isDryRun): void
    {
        $this->info('');
        $this->info('5. 检查current_plan_day与实际执行进度不一致的账号...');

        // 查找所有有计划且状态为processing或locking的账号
        $activeAccounts = ItunesTradeAccount::whereIn('status', [
                ItunesTradeAccount::STATUS_PROCESSING,
////                ItunesTradeAccount::STATUS_LOCKING,
//                ItunesTradeAccount::STATUS_WAITING
            ])
            ->with(['plan', 'exchangeLogs' => function($query) {
                $query->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                      ->orderBy('day', 'desc')
                      ->orderBy('exchange_time', 'desc');
            }])
            ->get();

        $inconsistentAccounts = [];

        foreach ($activeAccounts as $account) {
//            if (!$account->plan) {
//                continue;
//            }

            $currentPlanDay = $account->current_plan_day;

            // 获取最后一次成功兑换的天数
            $lastSuccessLog = $account->exchangeLogs->first();
            $lastCompletedDay = $lastSuccessLog ? $lastSuccessLog->day : 0;

            // 获取所有已完成的天数
            $completedDays = $account->exchangeLogs
                ->pluck('day')
                ->unique()
                ->sort()
                ->values()
                ->toArray();

            // 检查是否存在不一致
            $shouldBeDay = $this->calculateCorrectCurrentDay($account, $completedDays, $lastSuccessLog);

            if ($currentPlanDay != $shouldBeDay) {
                $inconsistentAccounts[] = [
                    'account' => $account,
                    'current_plan_day' => $currentPlanDay,
                    'should_be_day' => $shouldBeDay,
                    'last_completed_day' => $lastCompletedDay,
                    'completed_days' => $completedDays,
                    'last_success_log' => $lastSuccessLog
                ];
            }
        }

        if (empty($inconsistentAccounts)) {
            $this->info('未发现current_plan_day不一致的账号');
            return;
        }

        $this->getLogger()->warning("发现 " . count($inconsistentAccounts) . " 个账号的current_plan_day与实际进度不一致");

        foreach ($inconsistentAccounts as $item) {
            $this->processCurrentDayInconsistency($item, $isDryRun);
        }

        if ($isDryRun) {
            $this->info('');
            $this->info('以上是模拟结果，要实际修复请运行: php artisan fix:account-data-inconsistency');
        }
    }

         /**
      * 计算正确的当前计划天数
      */
     private function calculateCorrectCurrentDay(ItunesTradeAccount $account, array $completedDays, $lastSuccessLog): int
     {
         if (empty($completedDays)) {
             return 1; // 没有完成记录，应该是第1天
         }

         // 对于没有计划的账号（历史原因解绑），使用特殊逻辑
         if (!$account->plan) {
             return $this->calculateCurrentDayForUnboundAccount($account, $completedDays, $lastSuccessLog);
         }

         $planDays = $account->plan->plan_days;
         $lastCompletedDay = max($completedDays);

         // 如果所有天数都已完成，账号应该被标记为完成
         if (count($completedDays) >= $planDays) {
             return $planDays + 1; // 表示应该完成
         }

         // 检查是否应该进入下一天
         if ($lastSuccessLog) {
             $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
             $dayInterval = $account->plan->day_interval ?? 24; // 默认24小时间隔
             $hoursFromLastExchange = $lastExchangeTime->diffInHours(now());

             if ($hoursFromLastExchange >= $dayInterval) {
                 // 已经超过间隔时间，应该进入下一天
                 return $lastCompletedDay + 1;
             } else {
                 // 还在间隔时间内，应该等待
                 return $lastCompletedDay;
             }
         }

         return $lastCompletedDay + 1;
     }

     /**
      * 为解绑计划的账号计算正确的当前天数
      */
     private function calculateCurrentDayForUnboundAccount(ItunesTradeAccount $account, array $completedDays, $lastSuccessLog): int
     {
         $maxPlanDays = 3; // 最大计划天数为3天
         $lastCompletedDay = max($completedDays);

         // 如果已经完成了3天，应该标记为完成
         if ($lastCompletedDay >= $maxPlanDays) {
             return $maxPlanDays + 1; // 表示应该完成
         }

         // 获取最后一天的累计金额
         $lastDayAmount = ItunesTradeAccountLog::where('account_id', $account->id)
             ->where('day', $lastCompletedDay)
             ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
             ->sum('amount');

         // 如果最后一天累计金额达到或超过600，进入下一天
         if ($lastDayAmount >= 600) {
             $nextDay = $lastCompletedDay + 1;
             // 如果下一天超过3天，应该完成
             if ($nextDay > $maxPlanDays) {
                 return $maxPlanDays + 1; // 表示应该完成
             }
             return $nextDay;
         } else {
             // 金额不够600，继续当前天
             return $lastCompletedDay;
         }
     }

    /**
     * 处理current_plan_day不一致的账号
     */
    private function processCurrentDayInconsistency(array $item, bool $isDryRun): void
    {
        $account = $item['account'];
        $currentPlanDay = $item['current_plan_day'];
        $shouldBeDay = $item['should_be_day'];
        $completedDays = $item['completed_days'];
        $lastSuccessLog = $item['last_success_log'];

        $this->line("处理账号: {$account->account}");
        $this->line("  当前计划天数: {$currentPlanDay}");
        $this->line("  应该是天数: {$shouldBeDay}");
        $this->line("  已完成天数: " . implode(', ', $completedDays));

        if ($lastSuccessLog) {
            $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
            $this->line("  最后兑换时间: " . $lastExchangeTime->format('Y-m-d H:i:s'));
            $this->line("  距离现在: " . $lastExchangeTime->diffForHumans());
        }

                 // 检查是否应该完成（有计划的检查plan_days，无计划的检查是否超过3天）
         $maxDays = $account->plan ? $account->plan->plan_days : 3;

         if ($shouldBeDay > $maxDays) {
             // 应该完成
             $this->warn("  问题: 账号应该已完成但状态未更新");
             if (!$isDryRun) {
                 if ($account->plan) {
                     $this->markAccountCompleted($account);
                 } else {
                     // 无计划的账号，直接标记为完成
                     $this->markUnboundAccountCompleted($account, $completedDays);
                 }
                 $this->info("  修复: 已标记为完成状态");
             } else {
                 $this->info("  建议: 标记为完成状态");
             }
         } else {
             // 更新当前天数和状态
             $newStatus = ItunesTradeAccount::STATUS_PROCESSING; // 默认为processing

             // 如果有计划，根据时间间隔判断状态
             if ($account->plan) {
                 $dayInterval = $account->plan->day_interval ?? 24;
                 $hoursFromLastExchange = $lastSuccessLog ?
                     Carbon::parse($lastSuccessLog->exchange_time)->diffInHours(now()) : $dayInterval;

                 $newStatus = $hoursFromLastExchange >= $dayInterval ?
                     ItunesTradeAccount::STATUS_PROCESSING :
                     ItunesTradeAccount::STATUS_WAITING;
             }
             // 无计划的账号始终设为processing

             $this->warn("  问题: current_plan_day不正确");
             $this->info("  建议: 更新到第{$shouldBeDay}天，状态为" .
                 ($newStatus === ItunesTradeAccount::STATUS_PROCESSING ? 'PROCESSING' : 'WAITING'));

             if (!$isDryRun) {
                 // 更新completed_days字段
                 $completedDaysData = json_decode($account->completed_days ?? '{}', true) ?: [];
                 foreach ($completedDays as $day) {
                     $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                         ->where('day', $day)
                         ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                         ->sum('amount');
                     $completedDaysData[(string)$day] = $dailyAmount;
                 }

                 $account->update([
                     'current_plan_day' => $shouldBeDay,
                     'status' => $newStatus,
                     'completed_days' => json_encode($completedDaysData)
                 ]);

                 $this->info("  修复: 已更新到第{$shouldBeDay}天，状态为" .
                     ($newStatus === ItunesTradeAccount::STATUS_PROCESSING ? 'PROCESSING' : 'WAITING'));

                 $this->getLogger()->info('修复current_plan_day不一致', [
                     'account_id' => $account->id,
                     'account' => $account->account,
                     'old_current_plan_day' => $currentPlanDay,
                     'new_current_plan_day' => $shouldBeDay,
                     'new_status' => $newStatus,
                     'completed_days' => $completedDays,
                     'updated_completed_days' => $completedDaysData,
                     'has_plan' => !is_null($account->plan)
                 ]);
             }
         }

                 $this->line('');
     }

     /**
      * 标记无计划账号为完成状态
      */
     private function markUnboundAccountCompleted(ItunesTradeAccount $account, array $completedDays): void
     {
         // 计算completed_days数据
         $completedDaysData = [];
         foreach ($completedDays as $day) {
             $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                 ->where('day', $day)
                 ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                 ->sum('amount');
             $completedDaysData[(string)$day] = $dailyAmount;
         }

         $account->update([
             'status' => ItunesTradeAccount::STATUS_COMPLETED,
             'current_plan_day' => null,
             'plan_id' => null, // 确保计划ID为空
             'completed_days' => json_encode($completedDaysData),
         ]);

         $this->getLogger()->info('无计划账号标记完成（数据修复）', [
             'account_id' => $account->id,
             'account' => $account->account,
             'total_amount' => $account->amount,
             'completed_days_count' => count($completedDays),
             'final_completed_days' => $completedDaysData
         ]);

         // 发送完成通知
         $msg = "[强]无计划账号完成通知（数据修复）\n";
         $msg .= "---------------\n";
         $msg .= $account->account . "\n";
         $msg .= "完成天数: " . implode(', ', $completedDays);

         send_msg_to_wechat('44769140035@chatroom', $msg);
     }

     /**
      * 重新登录账号
      */
    private function reloginAccount(ItunesTradeAccount $account): array
    {
        try {
            $loginData = [
                'id' => $account->id,
                'username' => $account->account,
                'password' => $account->getDecryptedPassword(),
                'VerifyUrl' => $account->api_url
            ];

            // 调用登录接口
            $response = $this->giftCardApiClient->createLoginTask([$loginData]);

            if (isset($response['success']) && $response['success']) {
                return ['success' => true, 'message' => '登录成功'];
            } else {
                return ['success' => false, 'message' => $response['message'] ?? '登录失败'];
            }

        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
