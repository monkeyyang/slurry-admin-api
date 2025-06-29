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
    protected $signature = 'accounts:maintain-pools 
                            {--dry-run : 只显示统计信息，不执行实际更新}
                            {--force : 强制重建所有账号池}
                            {--amounts= : 指定要维护的面额列表，用逗号分隔}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '维护Redis中的账号池，根据账号状态和可兑换容量分配到不同面额池';

    private AccountPoolService $poolService;

    public function __construct(AccountPoolService $poolService)
    {
        parent::__construct();
        $this->poolService = $poolService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = microtime(true);
        
        $this->info('开始维护账号池...');
        
        $options = [
            'dry_run' => $this->option('dry-run'),
            'force' => $this->option('force'),
            'amounts' => $this->option('amounts') ? explode(',', $this->option('amounts')) : null
        ];
        
        try {
            // 显示维护前统计
            $this->showPoolStatistics('维护前');
            
            // 执行维护
            $result = $this->poolService->maintainPools($options);
            
            // 显示结果
            $this->displayMaintenanceResult($result);
            
            // 显示维护后统计
            if (!$options['dry_run']) {
                $this->showPoolStatistics('维护后');
            }
            
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("账号池维护完成，耗时: {$executionTime}ms");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('账号池维护失败: ' . $e->getMessage());
            Log::error('账号池维护异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return 1;
        }
    }
    
    /**
     * 显示账号池统计信息
     */
    private function showPoolStatistics(string $title)
    {
        $this->info("\n=== {$title}统计 ===");
        
        $stats = $this->poolService->getPoolStatistics();
        
        // 显示总体统计
        $this->table(['指标', '数值'], [
            ['活跃账号总数', $stats['total_active_accounts']],
            ['账号池总数', $stats['total_pools']],
            ['兜底账号数', $stats['fallback_accounts']],
            ['平均每池账号数', $stats['avg_accounts_per_pool']]
        ]);
        
        // 显示面额分布
        if (!empty($stats['amount_distribution'])) {
            $this->info("\n面额分布:");
            $amountData = [];
            foreach ($stats['amount_distribution'] as $amount => $count) {
                $amountData[] = ["面额 {$amount}", $count];
            }
            $this->table(['面额', '账号数'], $amountData);
        }
        
        // 显示热门池
        if (!empty($stats['top_pools'])) {
            $this->info("\n热门账号池:");
            $poolData = [];
            foreach ($stats['top_pools'] as $pool => $count) {
                $poolData[] = [$pool, $count];
            }
            $this->table(['账号池', '账号数'], $poolData);
        }
    }
    
    /**
     * 显示维护结果
     */
    private function displayMaintenanceResult(array $result)
    {
        $this->info("\n=== 维护结果 ===");
        
        $this->table(['操作', '数量'], [
            ['处理的账号', $result['processed_accounts']],
            ['创建的池', $result['created_pools']],
            ['更新的池', $result['updated_pools']],
            ['清理的池', $result['cleaned_pools']],
            ['添加到池的账号', $result['added_accounts']],
            ['从池移除的账号', $result['removed_accounts']]
        ]);
        
        if (!empty($result['errors'])) {
            $this->warn("\n处理错误:");
            foreach ($result['errors'] as $error) {
                $this->line("- {$error}");
            }
        }
        
        if (!empty($result['warnings'])) {
            $this->warn("\n警告信息:");
            foreach ($result['warnings'] as $warning) {
                $this->line("- {$warning}");
            }
        }
    }
} 