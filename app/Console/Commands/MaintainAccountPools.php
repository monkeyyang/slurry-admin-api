<?php

namespace App\Console\Commands;

use App\Models\ItunesTradeAccount;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * 账号状态维护命令（简化版）
 * 
 * 职责：
 * 1. 清理长期锁定的账号
 * 2. 检查和修复账号状态异常
 * 3. 维护账号基础状态，不再维护复杂的池系统
 */
class MaintainAccountPools extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'itunes:maintain-accounts {--dry-run : 仅显示将要执行的操作，不实际执行}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '账号状态维护 - 清理锁定状态、修复异常状态等基础维护操作';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $dryRun = $this->option('dry-run');
        $startTime = now();

        Log::info("========== 账号状态维护开始 [{$startTime}] ==========");

        if ($dryRun) {
            $this->info("🔍 DRY RUN 模式：只显示操作，不实际执行");
        }

        try {
            $stats = [
                'released_locks' => 0,
                'fixed_status' => 0,
                'cleaned_invalid' => 0
            ];

            // 1. 清理长期锁定的账号
            $stats['released_locks'] = $this->releaseStaleLocks($dryRun);

            // 2. 修复状态异常的账号
            $stats['fixed_status'] = $this->fixAbnormalStatus($dryRun);

            // 3. 清理无效状态的账号
            $stats['cleaned_invalid'] = $this->cleanInvalidAccounts($dryRun);

            // 4. 显示统计信息
            $this->displayStatistics($stats);

            $endTime = now();
            $duration = $endTime->diffInSeconds($startTime);

            Log::info("账号状态维护完成", [
                'duration_seconds' => $duration,
                'statistics' => $stats,
                'dry_run' => $dryRun
            ]);

            $this->info("✅ 账号状态维护完成，耗时：{$duration}秒");

        } catch (\Exception $e) {
            Log::error('账号状态维护过程中发生错误', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->error("❌ 维护过程发生错误: " . $e->getMessage());
        }
    }

    /**
     * 清理长期锁定的账号（超过30分钟的LOCKING状态）
     */
    private function releaseStaleLocks(bool $dryRun = false): int
    {
        $this->info("🔐 检查长期锁定的账号...");

        $staleThreshold = now()->subMinutes(30);
        
        $staleLockedAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_LOCKING)
            ->where('updated_at', '<', $staleThreshold)
            ->get();

        $releasedCount = 0;

        foreach ($staleLockedAccounts as $account) {
            $lockDuration = now()->diffInMinutes($account->updated_at);
            
            $this->warn("  📌 发现长期锁定账号: {$account->account} (锁定{$lockDuration}分钟)");

            if (!$dryRun) {
                $account->update([
                    'status' => ItunesTradeAccount::STATUS_PROCESSING
                ]);
                
                Log::info("释放长期锁定账号", [
                    'account_id' => $account->id,
                    'account_email' => $account->account,
                    'lock_duration_minutes' => $lockDuration
                ]);
            }

            $releasedCount++;
        }

        if ($releasedCount > 0) {
            $this->info("  ✅ 释放了 {$releasedCount} 个长期锁定的账号");
        } else {
            $this->info("  ✅ 没有发现长期锁定的账号");
        }

        return $releasedCount;
    }

    /**
     * 修复状态异常的账号
     */
    private function fixAbnormalStatus(bool $dryRun = false): int
    {
        $this->info("🔧 检查状态异常的账号...");

        $fixedCount = 0;

        // 检查1：登录状态为valid但账号状态不是processing的
        $invalidStatusAccounts = ItunesTradeAccount::where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->whereNotIn('status', [
                ItunesTradeAccount::STATUS_PROCESSING,
                ItunesTradeAccount::STATUS_LOCKING,
                ItunesTradeAccount::STATUS_WAITING,
                ItunesTradeAccount::STATUS_COMPLETED
            ])
            ->get();

        foreach ($invalidStatusAccounts as $account) {
            $this->warn("  ⚠️  状态异常账号: {$account->account} (login_status: {$account->login_status}, status: {$account->status})");

            if (!$dryRun) {
                // 根据余额决定新状态
                $newStatus = $account->amount > 0 
                    ? ItunesTradeAccount::STATUS_PROCESSING 
                    : ItunesTradeAccount::STATUS_WAITING;

                $account->update(['status' => $newStatus]);
                
                Log::info("修复状态异常账号", [
                    'account_id' => $account->id,
                    'account_email' => $account->account,
                    'old_status' => $account->status,
                    'new_status' => $newStatus,
                    'reason' => 'login_valid_but_status_abnormal'
                ]);
            }

            $fixedCount++;
        }

        // 检查2：状态为processing但登录状态不是valid的
        $invalidLoginAccounts = ItunesTradeAccount::whereIn('status', [
                ItunesTradeAccount::STATUS_PROCESSING,
                ItunesTradeAccount::STATUS_LOCKING
            ])
            ->where('login_status', '!=', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->get();

        foreach ($invalidLoginAccounts as $account) {
            $this->warn("  ⚠️  登录状态异常账号: {$account->account} (status: {$account->status}, login_status: {$account->login_status})");

            if (!$dryRun) {
                $account->update(['status' => ItunesTradeAccount::STATUS_WAITING]);
                
                Log::info("修复登录状态异常账号", [
                    'account_id' => $account->id,
                    'account_email' => $account->account,
                    'old_status' => $account->status,
                    'new_status' => ItunesTradeAccount::STATUS_WAITING,
                    'login_status' => $account->login_status,
                    'reason' => 'processing_but_login_invalid'
                ]);
            }

            $fixedCount++;
        }

        if ($fixedCount > 0) {
            $this->info("  ✅ 修复了 {$fixedCount} 个状态异常的账号");
        } else {
            $this->info("  ✅ 没有发现状态异常的账号");
        }

        return $fixedCount;
    }

    /**
     * 清理无效状态的账号
     */
    private function cleanInvalidAccounts(bool $dryRun = false): int
    {
        $this->info("🧹 检查无效状态的账号...");

        $cleanedCount = 0;

        // 清理余额为负数的账号
        $negativeBalanceAccounts = ItunesTradeAccount::where('amount', '<', 0)->get();

        foreach ($negativeBalanceAccounts as $account) {
            $this->warn("  ⚠️  负余额账号: {$account->account} (余额: {$account->amount})");

            if (!$dryRun) {
                $account->update([
                    'amount' => 0,
                    'status' => ItunesTradeAccount::STATUS_WAITING
                ]);
                
                Log::info("清理负余额账号", [
                    'account_id' => $account->id,
                    'account_email' => $account->account,
                    'old_amount' => $account->amount,
                    'new_amount' => 0
                ]);
            }

            $cleanedCount++;
        }

        if ($cleanedCount > 0) {
            $this->info("  ✅ 清理了 {$cleanedCount} 个无效状态的账号");
        } else {
            $this->info("  ✅ 没有发现无效状态的账号");
        }

        return $cleanedCount;
    }

    /**
     * 显示统计信息
     */
    private function displayStatistics(array $stats): void
    {
        $this->info("\n📊 维护统计信息:");
        $this->info("  • 释放锁定账号: {$stats['released_locks']} 个");
        $this->info("  • 修复异常状态: {$stats['fixed_status']} 个");
        $this->info("  • 清理无效账号: {$stats['cleaned_invalid']} 个");

        // 显示当前账号状态分布
        $this->info("\n📈 当前账号状态分布:");
        
        $statusDistribution = DB::table('itunes_trade_accounts')
            ->select('status', 'login_status', DB::raw('count(*) as count'))
            ->groupBy('status', 'login_status')
            ->orderBy('status')
            ->orderBy('login_status')
            ->get();

        foreach ($statusDistribution as $item) {
            $this->info("  • {$item->status} + {$item->login_status}: {$item->count} 个");
        }

        // 显示按国家分布
        $this->info("\n🌍 按国家分布 (processing + valid):");
        
        $countryDistribution = DB::table('itunes_trade_accounts')
            ->select('country_code', DB::raw('count(*) as count'))
            ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->groupBy('country_code')
            ->orderByDesc('count')
            ->get();

        foreach ($countryDistribution as $item) {
            $this->info("  • {$item->country_code}: {$item->count} 个可用账号");
        }
    }
} 