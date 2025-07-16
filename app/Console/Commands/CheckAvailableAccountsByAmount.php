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
 * 1. 统计账号金额分布
 * 2. 识别长期未使用的账号
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
    protected $description = '检查账号金额分布和长期未使用账号（每30分钟执行）';

    /**
     * 执行控制台命令
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $date = now();

        $this->getLogger()->info("========== 开始检查账号金额分布 [{$date}] ==========");
        $this->info("🔍 开始检查账号金额分布和长期未使用账号");

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

        // 如果没有指定国家，获取所有有账号的国家
        $countries = DB::table('itunes_trade_accounts')
            ->whereIn('status', [
                ItunesTradeAccount::STATUS_PROCESSING,
                ItunesTradeAccount::STATUS_WAITING
            ])
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

        // 获取该国家的所有processing和waiting状态的账号
        $accounts = ItunesTradeAccount::whereIn('status', [
                ItunesTradeAccount::STATUS_PROCESSING,
                ItunesTradeAccount::STATUS_WAITING
            ])
            ->where('country_code', $country)
            ->whereNull('deleted_at')
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn("  国家 {$country} 没有账号");
            return [
                'total_accounts' => 0,
                'amount_distribution' => [],
                'inactive_accounts' => [],
                'no_accounts' => true
            ];
        }

        $this->info("  总账号数: {$accounts->count()}");

        // 统计金额分布
        $amountDistribution = $this->getAmountDistribution($accounts);

        // 获取长期未使用的账号（2小时无兑换）
        $inactiveAccounts = $this->getInactiveAccounts($accounts);

        // 记录到日志
        $this->getLogger()->info("账号金额分布统计", [
            'country' => $country,
            'total_accounts' => $accounts->count(),
            'amount_distribution' => $amountDistribution,
            'inactive_count' => count($inactiveAccounts)
        ]);

        // 输出详细分析
        $this->outputDetailedAnalysis($country, $accounts, $amountDistribution, $inactiveAccounts);

        // 返回检测结果
        return [
            'total_accounts' => $accounts->count(),
            'amount_distribution' => $amountDistribution,
            'inactive_accounts' => $inactiveAccounts,
            'no_accounts' => false
        ];
    }

    /**
     * 获取金额分布
     */
    private function getAmountDistribution($accounts): array
    {
        return [
            '0' => $accounts->where('amount', 0)->count(),
            '0-600' => $accounts->whereBetween('amount', [0.01, 600])->count(),
            '600-1200' => $accounts->whereBetween('amount', [600.01, 1200])->count(),
            '1200-1650' => $accounts->whereBetween('amount', [1200.01, 1650])->count(),
            '1650+' => $accounts->where('amount', '>', 1650)->count(),
        ];
    }

    /**
     * 获取长期未使用的账号（1650以上且2小时无兑换）
     */
    private function getInactiveAccounts($accounts): array
    {
        $inactiveAccounts = [];
        $twoHoursAgo = now()->subHours(2);

        // 只检查1650以上的账号
        $highBalanceAccounts = $accounts->where('amount', '>', 0)->where('status', 'processing')->where('login_status', 'valid');

        foreach ($highBalanceAccounts as $account) {
            // 检查最近2小时是否有兑换记录，使用exchange_time字段
            $lastActivity = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->whereNotNull('exchange_time')
                ->orderBy('exchange_time', 'desc')
                ->first();

            if (!$lastActivity || $lastActivity->exchange_time < $twoHoursAgo) {
                $inactiveAccounts[] = [
                    'id' => $account->id,
                    'account' => $account->account,
                    'balance' => $account->amount,
                    'status' => $account->status,
                    'plan_id' => $account->plan_id,
                    'current_plan_day' => $account->current_plan_day ?? 1,
                    'last_activity' => $lastActivity ? $lastActivity->exchange_time->format('Y-m-d H:i:s') : '无记录',
                    'hours_inactive' => $lastActivity ? $lastActivity->exchange_time->diffInHours(now()) : 999,
                    'last_exchange_time' => $lastActivity ? $lastActivity->exchange_time->timestamp : 0
                ];
            }
        }

        // 按优先级排序：优先级排序 + 金额倒序 + 兑换时间正序
        usort($inactiveAccounts, function($a, $b) {
            // 1. 优先级排序：有计划的账号优先
            $aHasPlan = !empty($a['plan_id'] ?? null);
            $bHasPlan = !empty($b['plan_id'] ?? null);

            if ($aHasPlan !== $bHasPlan) {
                return $bHasPlan <=> $aHasPlan; // 有计划的优先
            }

            // 2. 金额倒序：余额高的优先（最大排最前）
            if ($a['balance'] !== $b['balance']) {
                return $b['balance'] <=> $a['balance']; // 金额倒序
            }

            // 3. 兑换时间正序：最早兑换的优先（最早排最前）
            return $a['last_exchange_time'] <=> $b['last_exchange_time']; // 时间正序
        });

        return $inactiveAccounts;
    }

    /**
     * 输出详细分析
     */
    private function outputDetailedAnalysis(string $country, $accounts, array $amountDistribution, array $inactiveAccounts): void
    {
        $this->line("  金额分布:");
        $this->line("    0余额: {$amountDistribution['0']} 个");
        $this->line("    0-600: {$amountDistribution['0-600']} 个");
        $this->line("    600-1200: {$amountDistribution['600-1200']} 个");
        $this->line("    1200-1650: {$amountDistribution['1200-1650']} 个");
        $this->line("    1650+: {$amountDistribution['1650+']} 个");

        if (!empty($inactiveAccounts)) {
            $count = count($inactiveAccounts);
            $this->line("  ⚠️  长期未使用账号（2小时无兑换 {$count}个）:");
            foreach ($inactiveAccounts as $account) {
                $this->line("    {$account['account']} - 余额:{$account['balance']} - 最后兑换:{$account['last_activity']} - 未使用:{$account['hours_inactive']}小时");
            }
        } else {
            $this->line("  ✅ 没有长期未使用的账号");
        }
    }

    /**
     * 发送检测结果到微信
     */
    private function sendResultsToWechat(array $allResults, float $executionTime): void
    {
        try {
            // 格式化微信消息
            $message = "💰 账号金额分布监控\n";
            $message .= "══════════════\n";
            $message .= "📅 检测时间: " . now()->format('Y-m-d H:i:s') . "\n";
            $message .= "⏱️ 执行耗时: {$executionTime}ms\n\n";

            foreach ($allResults as $country => $results) {
                if ($results['no_accounts']) {
                    $message .= "🌍 国家: {$country}\n";
                    $message .= "❌ 没有账号\n\n";
                    continue;
                }

                $message .= "🌍 国家: {$country}\n";
                $message .= "📊 总账号数: {$results['total_accounts']}\n";

                // 显示金额分布
                $message .= "💰 金额分布:\n";
                $distribution = $results['amount_distribution'];
                $message .= "  0余额: {$distribution['0']} 个\n";
                $message .= "  0-600: {$distribution['0-600']} 个\n";
                $message .= "  600-1200: {$distribution['600-1200']} 个\n";
                $message .= "  1200-1650: {$distribution['1200-1650']} 个\n";
                $message .= "  1650+: {$distribution['1650+']} 个\n";

                // 显示长期未使用的账号（只显示前6条）
                $inactiveAccounts = $results['inactive_accounts'];
                if (!empty($inactiveAccounts)) {
                    $message .= "\n⚠️  长期未使用账号（2小时无兑换）:\n";
                    $displayAccounts = array_slice($inactiveAccounts, 0, 6);
                    foreach ($displayAccounts as $account) {
                        $message .= "  {$account['account']} - 余额:{$account['balance']} - 当前计划天:{$account['current_plan_day']} - 未使用:{$account['hours_inactive']}小时\n";
                    }
                    if (count($inactiveAccounts) > 6) {
                        $message .= "  ... 还有 " . (count($inactiveAccounts) - 6) . " 个账号未显示\n";
                    }
                } else {
                    $message .= "\n✅ 没有长期未使用的账号\n";
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
