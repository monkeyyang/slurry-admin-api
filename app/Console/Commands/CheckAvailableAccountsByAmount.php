<?php

namespace App\Console\Commands;

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use App\Models\ItunesTradePlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * 检查各个面额可兑换的账号数量
 *
 * 职责：
 * 1. 检查50-500（以50为基数）各个面额的可兑换账号数量
 * 2. 考虑账号状态、登录状态、当日计划剩余额度
 * 3. 输出统计报告
 */
class CheckAvailableAccountsByAmount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'itunes:check-available-accounts {--country=* : 指定检查的国家代码，留空检查所有国家}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检查各个面额可兑换的账号数量（每30分钟执行）';

    /**
     * 面额列表（以50为基数，从50到500）
     */
    private const AMOUNTS = [50, 100, 150, 200, 250, 300, 350, 400, 450, 500];

    /**
     * 执行控制台命令
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $date = now();

        $this->getLogger()->info("========== 开始检查可兑换账号数量 [{$date}] ==========");
        $this->info("🔍 开始检查各个面额可兑换的账号数量");

        try {
            // 获取要检查的国家列表
            $countries = $this->getCountriesToCheck();
            
            if (empty($countries)) {
                $this->warn("没有找到需要检查的国家");
                return;
            }

            $this->info("检查国家: " . implode(', ', $countries));

            // 收集检测结果
            $allResults = [];
            foreach ($countries as $country) {
                $countryResults = $this->checkCountryAccounts($country);
                $allResults[$country] = $countryResults;
            }

            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000, 2);

            $this->getLogger()->info("检查完成", [
                'execution_time_ms' => $executionTime,
                'countries_checked' => count($countries)
            ]);

            $this->info("✅ 检查完成，耗时: {$executionTime}ms");

            // 发送结果到微信
            $this->sendResultsToWechat($allResults, $executionTime);

        } catch (\Exception $e) {
            $this->getLogger()->error('检查过程中发生错误: ' . $e->getMessage());
            $this->error('❌ 检查失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取要检查的国家列表
     */
    private function getCountriesToCheck(): array
    {
        $specifiedCountries = $this->option('country');
        
        if (!empty($specifiedCountries)) {
            return $specifiedCountries;
        }

        // 如果没有指定国家，获取所有有处理中账号的国家
        $countries = DB::table('itunes_trade_accounts')
            ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('country_code')
            ->filter()
            ->toArray();

        return $countries;
    }

    /**
     * 检查指定国家的账号
     */
    private function checkCountryAccounts(string $country): array
    {
        $this->info("\n📊 检查国家: {$country}");
        
        // 获取该国家的所有processing且登录有效的账号
        $accounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->where('country_code', $country)
            ->whereNull('deleted_at')
            ->with('plan')
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn("  国家 {$country} 没有可用账号");
            return [
                'total_accounts' => 0,
                'statistics' => [],
                'no_accounts' => true
            ];
        }

        $this->info("  总账号数: {$accounts->count()}");

        // 统计各个面额的可兑换账号数量
        $statistics = [];
        
        foreach (self::AMOUNTS as $amount) {
            $availableCount = $this->countAvailableAccountsForAmount($accounts, $amount);
            $statistics[$amount] = $availableCount;
            
            $this->line("  面额 $" . str_pad($amount, 3, ' ', STR_PAD_LEFT) . ": " . str_pad($availableCount, 3, ' ', STR_PAD_LEFT) . " 个账号");
        }

        // 收集详细统计信息
        $detailedStats = $this->collectDetailedStatistics($country, $accounts);

        // 记录到日志
        $this->getLogger()->info("账号可用性统计", [
            'country' => $country,
            'total_accounts' => $accounts->count(),
            'statistics' => $statistics,
            'detailed_stats' => $detailedStats
        ]);

        // 输出详细分析
        $this->outputDetailedAnalysis($country, $accounts, $statistics);

        // 返回检测结果
        return [
            'total_accounts' => $accounts->count(),
            'statistics' => $statistics,
            'detailed_stats' => $detailedStats,
            'no_accounts' => false
        ];
    }

    /**
     * 计算指定面额的可兑换账号数量
     */
    private function countAvailableAccountsForAmount($accounts, float $amount): int
    {
        $count = 0;

        foreach ($accounts as $account) {
            if ($this->canAccountRedeemAmount($account, $amount)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 检查账号是否可以兑换指定面额
     */
    private function canAccountRedeemAmount(ItunesTradeAccount $account, float $amount): bool
    {
        // 1. 如果账号余额为0，可以兑换所有面额
        if ($account->amount == 0) {
            return true;
        }

        // 2. 检查总额度限制
        if (!$account->plan) {
            // 没有计划的账号，假设无限制（但这种情况很少）
            return true;
        }

        $plan = $account->plan;
        
        // 检查总额度：当前余额 + 兑换金额 <= 计划总额度
        if (($account->amount + $amount) > $plan->total_amount) {
            return false;
        }

        // 3. 检查当日额度限制
        return $this->canAccountRedeemAmountToday($account, $plan, $amount);
    }

    /**
     * 检查账号今日是否可以兑换指定面额
     */
    private function canAccountRedeemAmountToday(ItunesTradeAccount $account, ItunesTradePlan $plan, float $amount): bool
    {
        $currentDay = $account->current_plan_day ?? 1;

        // 获取当天已成功兑换的总额
        $dailySpent = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        // 获取当天的计划额度
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;
        
        // 当日最大可兑换 = 计划金额 + 浮动金额
        $maxDailyAmount = $dailyLimit + ($plan->float_amount ?? 0);
        
        // 当日剩余可兑换 = 最大可兑换 - 已兑换
        $remainingDailyAmount = $maxDailyAmount - $dailySpent;

        // 检查是否足够兑换指定面额
        return $remainingDailyAmount >= $amount;
    }

    /**
     * 收集详细统计信息
     */
    private function collectDetailedStatistics(string $country, $accounts): array
    {
        // 获取所有相关账号（包含waiting状态的账号）
        $allAccounts = ItunesTradeAccount::whereIn('status', [
                ItunesTradeAccount::STATUS_PROCESSING,
                ItunesTradeAccount::STATUS_WAITING
            ])
            ->where('country_code', $country)
            ->whereNull('deleted_at')
            ->get();

        // 分析零余额账号（包含waiting状态）
        $zeroBalanceCount = $allAccounts->where('amount', 0)->count();

        // 分析有计划账号（包含waiting状态的账号）
        $withPlanCount = $allAccounts->whereNotNull('plan_id')->count();
        $withoutPlanCount = $allAccounts->whereNull('plan_id')->count();

        // 分析账号余额分布（包含waiting状态）
        $balanceRanges = [
            '0' => $allAccounts->where('amount', 0)->count(),
            '1-500' => $allAccounts->whereBetween('amount', [0.01, 500])->count(),
            '501-1000' => $allAccounts->whereBetween('amount', [501, 1000])->count(),
            '1001-1500' => $allAccounts->whereBetween('amount', [1001, 1500])->count(),
            '1500+' => $allAccounts->where('amount', '>', 1500)->count(),
        ];

        return [
            'zero_balance_count' => $zeroBalanceCount,
            'with_plan_count' => $withPlanCount,
            'without_plan_count' => $withoutPlanCount,
            'balance_ranges' => $balanceRanges
        ];
    }

    /**
     * 输出详细分析
     */
    private function outputDetailedAnalysis(string $country, $accounts, array $statistics): void
    {
        // 获取所有相关账号（包含waiting状态的账号）
        $allAccounts = ItunesTradeAccount::whereIn('status', [
                ItunesTradeAccount::STATUS_PROCESSING,
                ItunesTradeAccount::STATUS_WAITING
            ])
            ->where('country_code', $country)
            ->whereNull('deleted_at')
            ->get();

        // 分析零余额账号（包含waiting状态）
        $zeroBalanceCount = $allAccounts->where('amount', 0)->count();
        $this->line("  零余额账号: {$zeroBalanceCount} 个（可兑换所有面额）");

        // 分析有计划账号（包含waiting状态的账号）
        $withPlanCount = $allAccounts->whereNotNull('plan_id')->count();
        $withoutPlanCount = $allAccounts->whereNull('plan_id')->count();
        $this->line("  有计划账号: {$withPlanCount} 个");
        $this->line("  无计划账号: {$withoutPlanCount} 个");

        // 分析账号余额分布（包含waiting状态）
        $balanceRanges = [
            '0' => $allAccounts->where('amount', 0)->count(),
            '1-500' => $allAccounts->whereBetween('amount', [0.01, 500])->count(),
            '501-1000' => $allAccounts->whereBetween('amount', [501, 1000])->count(),
            '1001-1500' => $allAccounts->whereBetween('amount', [1001, 1500])->count(),
            '1500+' => $allAccounts->where('amount', '>', 1500)->count(),
        ];

        $this->line("  余额分布:");
        foreach ($balanceRanges as $range => $count) {
            $this->line("    ${range}: {$count} 个");
        }

        // 找出瓶颈面额（可用账号数量显著下降的面额）
        $bottleneckAmounts = $this->findBottleneckAmounts($statistics);
        if (!empty($bottleneckAmounts)) {
            $this->line("  ⚠️  瓶颈面额: $" . implode(', $', $bottleneckAmounts));
        }
    }

    /**
     * 找出瓶颈面额
     */
    private function findBottleneckAmounts(array $statistics): array
    {
        $bottlenecks = [];
        $previousCount = null;

        foreach ($statistics as $amount => $count) {
            if ($previousCount !== null) {
                // 如果当前面额的可用账号数比上一个面额减少超过20%，视为瓶颈
                $reductionRate = ($previousCount - $count) / $previousCount;
                if ($reductionRate > 0.2 && $count < 10) {
                    $bottlenecks[] = $amount;
                }
            }
            $previousCount = $count;
        }

        return $bottlenecks;
    }

    /**
     * 发送检测结果到微信
     */
    private function sendResultsToWechat(array $allResults, float $executionTime): void
    {
        try {
            // 格式化微信消息
            $message = "可用账号监控\n---------------------\n";
            $message .= "检测时间: " . now()->format('Y-m-d H:i:s') . "\n";
            $message .= "执行耗时: {$executionTime}ms\n\n";

            foreach ($allResults as $country => $results) {
                if ($results['no_accounts']) {
                    $message .= "📊 国家: {$country}\n";
                    $message .= "⚠️ 没有可用账号\n\n";
                    continue;
                }

                $message .= "📊 国家: {$country}\n";
                $message .= "总账号数: {$results['total_accounts']}\n";
                
                // 显示各面额的可用账号数量
                foreach ($results['statistics'] as $amount => $count) {
                    $message .= "  $" . str_pad($amount, 3, ' ', STR_PAD_LEFT) . ": " . str_pad($count, 3, ' ', STR_PAD_LEFT) . " 个\n";
                }
                
                // 标记瓶颈面额
                $bottleneckAmounts = $this->findBottleneckAmounts($results['statistics']);
                if (!empty($bottleneckAmounts)) {
                    $message .= "⚠️ 瓶颈面额: $" . implode(', $', $bottleneckAmounts) . "\n";
                }
                
                // 显示详细统计信息
                if (isset($results['detailed_stats'])) {
                    $stats = $results['detailed_stats'];
                    $message .= "零余额账号: {$stats['zero_balance_count']} 个（可兑换所有面额）\n";
                    $message .= "有计划账号: {$stats['with_plan_count']} 个\n";
                    $message .= "无计划账号: {$stats['without_plan_count']} 个\n";
                    $message .= "余额分布:\n";
                    foreach ($stats['balance_ranges'] as $range => $count) {
                        $message .= "  {$range}: {$count} 个\n";
                    }
                }
                
                $message .= "\n";
            }

            // 发送到微信群
            $roomId = '45958721463@chatroom'; // 使用管理群
            $sendResult = send_msg_to_wechat($roomId, $message);

            if ($sendResult) {
                $this->info("✅ 检测结果已发送到微信群");
                $this->getLogger()->info("检测结果发送到微信成功", [
                    'room_id' => $roomId,
                    'message_length' => strlen($message)
                ]);
            } else {
                $this->warn("⚠️ 微信消息发送失败");
                $this->getLogger()->error("检测结果发送到微信失败", [
                    'room_id' => $roomId
                ]);
            }

        } catch (\Exception $e) {
            $this->error("❌ 发送微信消息时发生错误: " . $e->getMessage());
            $this->getLogger()->error("发送微信消息异常", [
                'error' => $e->getMessage()
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
} 