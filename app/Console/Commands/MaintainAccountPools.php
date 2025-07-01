<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AccountPoolService;
use Illuminate\Support\Facades\Log;

class MaintainAccountPools extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pools:maintain 
                           {--force : 强制重建所有池子}
                           {--stats : 显示池子统计信息}
                           {--cleanup : 仅清理无效池子}
                           {--dry-run : 仅显示将要执行的操作，不实际执行}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '维护账号池 - 按国家、金额、计划、群聊分组，余额降序排列';

    private AccountPoolService $poolService;

    public function __construct(AccountPoolService $poolService)
    {
        parent::__construct();
        $this->poolService = $poolService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $date = now();
        Log::info("========== 账号池维护开始 [{$date}] ==========");

        if ($this->option('dry-run')) {
            $this->info("🔍 DRY RUN 模式：只显示操作，不实际执行");
        }

        try {
            // 显示统计信息
            if ($this->option('stats')) {
                $this->showPoolStatistics();
                return;
            }

            // 仅清理模式
            if ($this->option('cleanup')) {
                $this->cleanupOnly();
                return;
            }

            // 完整维护
            $this->performFullMaintenance();

        } catch (\Exception $e) {
            Log::error('账号池维护过程中发生错误: ' . $e->getMessage());
            Log::error('错误详情', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error("❌ 维护过程出错: " . $e->getMessage());
        }
    }
    /**
     * 显示池子统计信息
     */
    private function showPoolStatistics(): void
    {
        $this->info("📊 获取账号池统计信息...");
        
        $stats = $this->poolService->getPoolStats();
        
        if (empty($stats)) {
            $this->warn("⚠️  没有找到任何账号池");
            return;
        }

        $this->info("📈 账号池统计报告");
        $this->line("=" . str_repeat("=", 80));

        $totalPools = count($stats);
        $totalAccounts = array_sum(array_column($stats, 'count'));
        
        $this->info("📋 总体概况:");
        $this->line("   • 总池数: {$totalPools}");
        $this->line("   • 总账号数: {$totalAccounts}");
        $this->line("   • 平均每池账号数: " . ($totalPools > 0 ? round($totalAccounts / $totalPools, 2) : 0));
        $this->line("");

        // 按池子大小排序
        uasort($stats, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        $this->info("🏆 前10个最大的池子:");
        $count = 0;
        foreach ($stats as $poolKey => $stat) {
            if (++$count > 10) break;
            
            $this->line(sprintf("   %2d. %-40s 账号数: %3d  最高余额: %.2f", 
                $count, 
                $this->formatPoolKey($poolKey), 
                $stat['count'], 
                $stat['top_balance']
            ));
        }

        $this->line("");
        $this->info("💡 提示: 使用 --force 选项重建所有池子");
    }

    /**
     * 格式化池子key显示
     */
    private function formatPoolKey(string $poolKey): string
    {
        // 提取池子信息: account_pool_ca_500_room1_plan1
        $parts = explode('_', str_replace('account_pool_', '', $poolKey));
        
        if (count($parts) >= 2) {
            $country = strtoupper($parts[0]);
            $amount = $parts[1];
            $room = isset($parts[2]) && str_starts_with($parts[2], 'room') ? $parts[2] : '';
            $plan = isset($parts[3]) && str_starts_with($parts[3], 'plan') ? $parts[3] : '';
            
            $display = "{$country}-{$amount}";
            if ($room) $display .= " {$room}";
            if ($plan) $display .= " {$plan}";
            
            return $display;
        }
        
        return $poolKey;
    }

    /**
     * 仅清理无效池子
     */
    private function cleanupOnly(): void
    {
        $this->info("🧹 开始清理无效池子...");

        if (!$this->option('dry-run')) {
            $this->poolService->cleanupPools();
        }

        $this->info("✅ 清理完成");
    }

    /**
     * 执行完整维护
     */
    private function performFullMaintenance(): void
    {
        $this->info("🔧 开始完整账号池维护...");

        // 维护选项
        $options = [
            'force' => $this->option('force'),
            'dry_run' => $this->option('dry-run')
        ];

        if ($this->option('force')) {
            $this->warn("⚠️  强制重建模式：将清空所有现有池子");
        }

        // 执行维护
        if (!$this->option('dry-run')) {
            $result = $this->poolService->maintainPools($options);
            $this->displayMaintenanceResults($result);
        } else {
            $this->info("🔍 DRY RUN: 模拟维护操作完成");
        }

        // 显示最终统计
        $this->line("");
        $this->info("📊 维护后统计信息:");
        $stats = $this->poolService->getPoolStats();
        
        $totalPools = count($stats);
        $totalAccounts = array_sum(array_column($stats, 'count'));
        
        $this->line("   • 活跃池数: {$totalPools}");
        $this->line("   • 池中总账号数: {$totalAccounts}");

        if ($totalPools > 0) {
            $this->line("   • 平均每池账号数: " . round($totalAccounts / $totalPools, 2));
            
            // 显示最大的5个池子
            uasort($stats, function($a, $b) {
                return $b['count'] <=> $a['count'];
            });
            
            $this->line("   • 最大池子:");
            $count = 0;
            foreach ($stats as $poolKey => $stat) {
                if (++$count > 5) break;
                $this->line("     - " . $this->formatPoolKey($poolKey) . ": {$stat['count']} 账号");
            }
        }

        $this->info("✅ 账号池维护完成");
    }

    /**
     * 显示维护结果
     */
    private function displayMaintenanceResults(array $result): void
    {
        $this->line("");
        $this->info("📋 维护结果:");
        
        if ($result['processed_accounts'] > 0) {
            $this->line("   • 处理账号数: {$result['processed_accounts']}");
        }
        
        if ($result['created_pools'] > 0) {
            $this->line("   • 创建池数: {$result['created_pools']}");
        }
        
        if ($result['updated_pools'] > 0) {
            $this->line("   • 更新池数: {$result['updated_pools']}");
        }
        
        if ($result['cleaned_pools'] > 0) {
            $this->line("   • 清理池数: {$result['cleaned_pools']}");
        }
        
        if ($result['added_accounts'] > 0) {
            $this->line("   • 添加账号次数: {$result['added_accounts']}");
        }
        
        if ($result['removed_accounts'] > 0) {
            $this->line("   • 移除账号次数: {$result['removed_accounts']}");
        }

        // 显示错误和警告
        if (!empty($result['errors'])) {
            $this->line("");
            $this->error("❌ 错误:");
            foreach ($result['errors'] as $error) {
                $this->line("   • {$error}");
            }
        }

        if (!empty($result['warnings'])) {
            $this->line("");
            $this->warn("⚠️  警告:");
            foreach ($result['warnings'] as $warning) {
                $this->line("   • {$warning}");
            }
        }
    }
} 