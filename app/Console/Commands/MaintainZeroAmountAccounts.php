<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAppleAccountLoginJob;
use App\Models\ItunesTradeAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * 零余额账号维护命令
 * 
 * 职责：
 * 1. 维护50个零余额且登录有效的账号
 * 2. 通过队列处理批量登录
 * 3. 显示详细的账号信息
 */
class MaintainZeroAmountAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'itunes:maintain-zero-accounts {--dry-run : 仅显示将要执行的操作，不实际执行}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '维护零余额账号 - 确保有50个零余额且登录有效的账号';

    private bool $dryRun;
    private const TARGET_ZERO_AMOUNT_ACCOUNTS = 50; // 目标零余额账号数量

    /**
     * 执行控制台命令
     */
    public function handle(): void
    {
        $this->dryRun = $this->option('dry-run');
        $date = now();

        $this->getLogger()->info("========== 零余额账号维护开始 [{$date}] ==========");

        if ($this->dryRun) {
            $this->info("🔍 DRY RUN 模式：只显示操作，不实际执行");
        }

        try {
            $this->maintainZeroAmountAccounts();
            $this->getLogger()->info('零余额账号维护完成');

        } catch (\Exception $e) {
            $this->getLogger()->error('零余额账号维护过程中发生错误: ' . $e->getMessage());
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
        $this->getLogger()->info("=== 零余额账号维护 ===");

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

        // 通过队列批量登录账号
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
     * 通过队列批量登录账号
     */
    private function queueBatchLoginAccounts($accounts, int $targetCount): void
    {
        if ($accounts->isEmpty()) {
            $this->getLogger()->info("📋 批量登录：无账号需要处理");
            return;
        }

        $this->getLogger()->info("🚀 开始批量创建零余额账号登录任务", [
            'total_accounts' => $accounts->count(),
            'target_success_count' => $targetCount,
            'account_list' => $accounts->pluck('account')->toArray()
        ]);

        $loginTaskCount = 0;

        // 为每个账号创建单独的登录任务
        foreach ($accounts->take($targetCount * 2) as $index => $account) {
            try {
                // 检查是否需要登录
                if ($account->login_status === ItunesTradeAccount::STATUS_LOGIN_ACTIVE) {
                    $this->info("   " . ($index + 1) . ". {$account->account} -> 已经登录，跳过");
                    continue;
                }

                // 创建登录任务
                ProcessAppleAccountLoginJob::dispatch($account->id, 'zero_amount_maintenance');
                $loginTaskCount++;

                $this->info("   " . ($index + 1) . ". {$account->account} -> 登录任务已创建");
                
                $this->getLogger()->info("零余额账号登录任务创建", [
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
                $this->getLogger()->error("❌ 为账号 {$account->account} 创建登录任务失败", [
                    'account_id' => $account->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->getLogger()->info("✅ 零余额账号登录任务创建完成", [
            'created_tasks' => $loginTaskCount,
            'target_count' => $targetCount,
            'note' => '任务将在后台队列中处理，状态更新会在后续检查中确认'
        ]);

        $this->info("🎯 零余额账号登录任务总结:");
        $this->info("   创建任务数: {$loginTaskCount}");
        $this->info("   目标成功数: {$targetCount}");
        $this->info("   处理方式: 后台队列异步处理");
        $this->info("   备注: 任务包含重试机制，失败会自动重试");
    }

    private function getLogger(): LoggerInterface
    {
        return Log::channel('kernel_process_accounts');
    }
} 