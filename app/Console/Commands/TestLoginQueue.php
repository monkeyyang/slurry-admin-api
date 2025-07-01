<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAppleAccountLoginJob;
use App\Models\ItunesTradeAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 测试登录队列功能
 * 
 * 用于验证：
 * 1. 防重复处理机制
 * 2. 轮询状态机制
 * 3. 重试机制
 */
class TestLoginQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:login-queue {account_id? : 指定要测试的账号ID} {--multiple : 测试防重复机制}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试登录队列功能 - 验证防重复处理和轮询机制';

    /**
     * 执行控制台命令
     */
    public function handle(): void
    {
        $accountId = $this->argument('account_id');
        $testMultiple = $this->option('multiple');

        if ($accountId) {
            $account = ItunesTradeAccount::find($accountId);
            if (!$account) {
                $this->error("账号ID {$accountId} 不存在");
                return;
            }

            if ($testMultiple) {
                $this->testDuplicateProcessing($account);
            } else {
                $this->testSingleLogin($account);
            }
        } else {
            $this->showSystemStatus();
        }
    }

    /**
     * 测试单个账号登录
     */
    private function testSingleLogin(ItunesTradeAccount $account): void
    {
        $this->info("🧪 测试账号登录: {$account->account}");
        
        // 显示当前状态
        $this->table(
            ['属性', '当前值'],
            [
                ['账号', $account->account],
                ['状态', $account->status],
                ['登录状态', $account->login_status],
                ['余额', $account->amount],
                ['国家', $account->country_code],
                ['今日重试次数', $this->getTodayAttempts($account->account)]
            ]
        );

        // 创建登录任务
        $this->info("📤 创建登录任务...");
        ProcessAppleAccountLoginJob::dispatch($account->id, 'manual_test');
        
        $this->info("✅ 登录任务已加入队列");
        $this->info("📊 可以通过以下命令监控队列:");
        $this->line("   php artisan queue:monitor account_operations");
        $this->line("   php artisan queue:failed");
    }

    /**
     * 测试防重复处理机制
     */
    private function testDuplicateProcessing(ItunesTradeAccount $account): void
    {
        $this->info("🔒 测试防重复处理机制: {$account->account}");
        
        // 创建多个相同的任务
        $taskCount = 5;
        $this->info("📤 创建 {$taskCount} 个相同的登录任务...");
        
        for ($i = 1; $i <= $taskCount; $i++) {
            ProcessAppleAccountLoginJob::dispatch($account->id, "duplicate_test_{$i}");
            $this->line("   任务 {$i} 已创建");
        }
        
        $this->info("✅ 所有任务已加入队列");
        $this->warn("⚠️  预期结果: 只有第一个任务会实际处理，其他会被跳过");
        
        // 显示锁状态
        $lockKey = "login_processing_" . $account->id;
        $lockExists = Cache::has($lockKey);
        
        $this->info("🔐 锁状态检查:");
        $this->line("   锁键: {$lockKey}");
        $this->line("   锁存在: " . ($lockExists ? '是' : '否'));
        
        if ($lockExists) {
            $lockValue = Cache::get($lockKey);
            $this->line("   锁值: {$lockValue}");
        }
    }

    /**
     * 显示系统状态概览
     */
    private function showSystemStatus(): void
    {
        $this->info("📊 iTunes账号登录系统状态概览");
        
        // 统计各状态账号数量
        $statusStats = [
            ['WAITING', ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_WAITING)->count()],
            ['PROCESSING', ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)->count()],
            ['LOCKING', ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_LOCKING)->count()],
            ['COMPLETED', ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_COMPLETED)->count()],
        ];
        
        $this->table(['账号状态', '数量'], $statusStats);
        
        // 统计登录状态
        $loginStats = [
            ['已登录', ItunesTradeAccount::where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)->count()],
            ['未登录', ItunesTradeAccount::where('login_status', ItunesTradeAccount::STATUS_LOGIN_INVALID)->count()],
        ];
        
        $this->table(['登录状态', '数量'], $loginStats);
        
        // 零余额账号统计
        $zeroAmountActive = ItunesTradeAccount::where('amount', 0)
            ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->count();
            
        $this->info("💰 零余额登录账号: {$zeroAmountActive} / 50");
        
        // 显示正在处理的锁
        $this->info("🔐 当前处理锁:");
        $pattern = "login_processing_*";
        $keys = Cache::store('redis')->getRedis()->keys($pattern);
        
        if (empty($keys)) {
            $this->line("   无账号正在处理");
        } else {
            foreach ($keys as $key) {
                $accountId = str_replace('login_processing_', '', $key);
                $lockValue = Cache::get($key);
                $this->line("   账号 {$accountId}: {$lockValue}");
            }
        }
        
        // 使用示例
        $this->info("📖 使用示例:");
        $this->line("   测试单个账号: php artisan test:login-queue 123");
        $this->line("   测试防重复: php artisan test:login-queue 123 --multiple");
    }

    /**
     * 获取今日重试次数
     */
    private function getTodayAttempts(string $account): int
    {
        $cacheKey = "login_attempts_" . md5($account) . "_" . now()->format('Y-m-d');
        return (int) Cache::get($cacheKey, 0);
    }
} 