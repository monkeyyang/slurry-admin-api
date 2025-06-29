<?php

require_once __DIR__ . '/vendor/autoload.php';

// 正确初始化Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';

// 启动应用
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use App\Services\Gift\GiftCardService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

echo "开始排序性能专项测试...\n";
echo str_repeat("=", 80) . "\n";

try {
    // 1. 获取测试数据
    $roomId = '50165570842@chatroom';
    $giftCardInfo = [
        'amount' => 200.00,
        'country_code' => 'CA',
        'currency' => 'USD',
        'valid' => true
    ];
    
    // 获取计划
    $plan = ItunesTradePlan::with('rate')->where('status', ItunesTradePlan::STATUS_ENABLED)->first();
    if (!$plan) {
        echo "❌ 没有找到可用计划\n";
        exit(1);
    }
    
    echo "测试配置:\n";
    echo "- 计划ID: {$plan->id}\n";
    echo "- 房间ID: {$roomId}\n";
    echo "- 礼品卡金额: \${$giftCardInfo['amount']}\n";
    echo "\n";
    
    // 2. 获取候选账号（限制数量避免太慢）
    $candidateAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
        ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
        ->limit(200) // 限制到200个进行测试
        ->get();
    
    if ($candidateAccounts->count() == 0) {
        echo "❌ 没有找到候选账号\n";
        exit(1);
    }
    
    echo "候选账号数量: {$candidateAccounts->count()}\n\n";
    
    // 3. 创建GiftCardService实例
    $giftCardService = new GiftCardService(app(\App\Services\GiftCardExchangeService::class));
    
    // 4. 使用反射获取排序方法
    $reflection = new ReflectionClass($giftCardService);
    $sortMethod = $reflection->getMethod('sortAccountsByPriority');
    $sortMethod->setAccessible(true);
    
    // 5. 执行多次排序测试
    echo "开始排序性能测试 (测试5次):\n";
    echo str_repeat("-", 50) . "\n";
    
    $times = [];
    $memoryUsages = [];
    
    for ($i = 1; $i <= 5; $i++) {
        // 清理内存
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        try {
            $sortedAccounts = $sortMethod->invoke(
                $giftCardService,
                $candidateAccounts,
                $plan,
                $roomId,
                $giftCardInfo
            );
            
            $endTime = microtime(true);
            $endMemory = memory_get_usage();
            
            $executionTime = ($endTime - $startTime) * 1000;
            $memoryUsage = $endMemory - $startMemory;
            
            $times[] = $executionTime;
            $memoryUsages[] = $memoryUsage;
            
            echo sprintf(
                "第%d次: %8.2f ms | %6.2f KB | 结果: %d 个账号\n",
                $i,
                $executionTime,
                $memoryUsage / 1024,
                $sortedAccounts->count()
            );
            
            // 验证排序结果
            if ($i == 1) {
                echo "  └─ 第一个账号ID: " . ($sortedAccounts->first()->id ?? 'NULL') . "\n";
                echo "  └─ 第一个账号邮箱: " . ($sortedAccounts->first()->account ?? 'NULL') . "\n";
            }
            
        } catch (Exception $e) {
            echo "第{$i}次测试失败: " . $e->getMessage() . "\n";
            continue;
        }
    }
    
    // 6. 统计分析
    if (!empty($times)) {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "排序性能统计分析:\n";
        
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        $avgMemory = array_sum($memoryUsages) / count($memoryUsages);
        
        echo sprintf("- 平均时间: %8.2f ms\n", $avgTime);
        echo sprintf("- 最快时间: %8.2f ms\n", $minTime);
        echo sprintf("- 最慢时间: %8.2f ms\n", $maxTime);
        echo sprintf("- 平均内存: %8.2f KB\n", $avgMemory / 1024);
        echo sprintf("- 账号数量: %d\n", $candidateAccounts->count());
        echo sprintf("- 每账号耗时: %6.3f ms\n", $avgTime / $candidateAccounts->count());
        
        // 性能评估
        echo "\n性能评估:\n";
        if ($avgTime < 50) {
            echo "✅ 排序性能优秀 (< 50ms)\n";
        } elseif ($avgTime < 200) {
            echo "⚠️  排序性能一般 (50-200ms)\n";
        } elseif ($avgTime < 1000) {
            echo "⚠️  排序性能较慢 (200ms-1s)\n";
        } else {
            echo "❌ 排序性能很慢 (> 1s)\n";
        }
        
        // 预测更大数据集的性能
        $accountCounts = [500, 800, 1000, 1500];
        echo "\n📈 性能预测 (基于当前 {$candidateAccounts->count()} 个账号的测试结果):\n";
        
        foreach ($accountCounts as $count) {
            // 排序复杂度通常是 O(n log n)
            $predictedTime = $avgTime * ($count / $candidateAccounts->count()) * log($count) / log($candidateAccounts->count());
            echo sprintf("- %4d 个账号预计耗时: %8.2f ms\n", $count, $predictedTime);
        }
    }
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "排序性能测试完成！\n";
    
} catch (Exception $e) {
    echo "❌ 测试过程中发生错误: " . $e->getMessage() . "\n";
    echo "错误堆栈:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} 