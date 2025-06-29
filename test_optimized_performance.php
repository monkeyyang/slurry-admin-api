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

echo "测试优化后的 findAvailableAccount 性能...\n";
echo str_repeat("=", 80) . "\n";

try {
    // 1. 使用与之前相同的测试数据
    $giftCardCode = 'XMKQH9WHC362QK6H';
    $roomId = '50165570842@chatroom';
    $msgId = '1111111111';
    $wxId = '2222222';
    
    $giftCardInfo = [
        'amount' => 200.00,
        'country_code' => 'CA',
        'currency' => 'USD',
        'valid' => true
    ];
    
    echo "测试数据:\n";
    echo "- 礼品卡码: {$giftCardCode}\n";
    echo "- 房间ID: {$roomId}\n";
    echo "- 礼品卡金额: \${$giftCardInfo['amount']}\n";
    echo "- 国家代码: {$giftCardInfo['country_code']}\n";
    echo "\n";
    
    // 2. 获取计划
    $plan = ItunesTradePlan::with('rate')->where('status', ItunesTradePlan::STATUS_ENABLED)->first();
    if (!$plan) {
        echo "❌ 没有找到可用计划\n";
        exit(1);
    }
    
    echo "使用的计划:\n";
    echo "- 计划ID: {$plan->id}\n";
    echo "- 总金额: {$plan->total_amount}\n";
    echo "- 浮动金额: {$plan->float_amount}\n";
    echo "- 绑定房间: " . ($plan->bind_room ? '是' : '否') . "\n";
    echo "\n";
    
    // 3. 创建GiftCardService实例
    $giftCardService = new GiftCardService(app(\App\Services\GiftCardExchangeService::class));
    
    // 设置礼品卡服务参数
    $giftCardService->setGiftCardCode($giftCardCode)
        ->setRoomId($roomId)
        ->setCardType('fast')
        ->setCardForm('image')
        ->setBatchId('test_batch_' . time())
        ->setMsgId($msgId)
        ->setWxId($wxId);
    
    // 4. 使用反射获取findAvailableAccount方法
    $reflection = new ReflectionClass($giftCardService);
    $findAvailableAccountMethod = $reflection->getMethod('findAvailableAccount');
    $findAvailableAccountMethod->setAccessible(true);
    
    // 5. 执行多次测试
    echo "开始性能测试 (优化后版本):\n";
    echo str_repeat("-", 60) . "\n";
    
    $results = [];
    $testCount = 5;
    
    for ($i = 1; $i <= $testCount; $i++) {
        echo "第 {$i} 次测试:\n";
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        try {
            $account = $findAvailableAccountMethod->invoke(
                $giftCardService, 
                $plan, 
                $roomId, 
                $giftCardInfo
            );
            
            $endTime = microtime(true);
            $endMemory = memory_get_usage();
            
            $executionTime = ($endTime - $startTime) * 1000;
            $memoryUsage = $endMemory - $startMemory;
            
            $results[] = [
                'success' => true,
                'time_ms' => $executionTime,
                'memory_bytes' => $memoryUsage,
                'account_id' => $account ? $account->id : null,
                'account_email' => $account ? $account->account : null
            ];
            
            echo "  ✅ 成功找到账号\n";
            echo "  └─ 账号ID: {$account->id}\n";
            echo "  └─ 账号邮箱: {$account->account}\n";
            echo "  └─ 账号余额: \${$account->amount}\n";
            echo "  └─ 执行时间: " . number_format($executionTime, 2) . " ms\n";
            echo "  └─ 内存使用: " . number_format($memoryUsage / 1024, 2) . " KB\n";
            
        } catch (Exception $e) {
            $endTime = microtime(true);
            $endMemory = memory_get_usage();
            
            $executionTime = ($endTime - $startTime) * 1000;
            $memoryUsage = $endMemory - $startMemory;
            
            $results[] = [
                'success' => false,
                'time_ms' => $executionTime,
                'memory_bytes' => $memoryUsage,
                'error' => $e->getMessage()
            ];
            
            echo "  ❌ 查找失败\n";
            echo "  └─ 错误信息: {$e->getMessage()}\n";
            echo "  └─ 执行时间: " . number_format($executionTime, 2) . " ms\n";
        }
        
        echo "\n";
        
        // 避免缓存影响
        if ($i < $testCount) {
            usleep(100000); // 100ms
        }
    }
    
    // 6. 性能统计分析
    echo str_repeat("=", 80) . "\n";
    echo "优化后性能统计分析:\n";
    
    $successfulResults = array_filter($results, function($r) { return $r['success']; });
    $failedResults = array_filter($results, function($r) { return !$r['success']; });
    
    echo "- 成功次数: " . count($successfulResults) . " / {$testCount}\n";
    echo "- 失败次数: " . count($failedResults) . " / {$testCount}\n";
    
    if (!empty($successfulResults)) {
        $times = array_column($successfulResults, 'time_ms');
        $memories = array_column($successfulResults, 'memory_bytes');
        
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        
        echo "- 执行时间统计:\n";
        echo "  └─ 平均: " . number_format($avgTime, 2) . " ms\n";
        echo "  └─ 最小: " . number_format($minTime, 2) . " ms\n";
        echo "  └─ 最大: " . number_format($maxTime, 2) . " ms\n";
        
        echo "- 内存使用统计:\n";
        echo "  └─ 平均: " . number_format(array_sum($memories) / count($memories) / 1024, 2) . " KB\n";
        echo "  └─ 最小: " . number_format(min($memories) / 1024, 2) . " KB\n";
        echo "  └─ 最大: " . number_format(max($memories) / 1024, 2) . " KB\n";
        
        // 性能评估和对比
        echo "\n🚀 性能评估:\n";
        
        // 与之前的结果对比（之前平均2524ms）
        $previousAvgTime = 2524.10; // 之前的平均时间
        
        if ($avgTime < 100) {
            echo "✅ 性能优秀 (< 100ms)\n";
        } elseif ($avgTime < 500) {
            echo "⚠️  性能一般 (100-500ms)\n";
        } elseif ($avgTime < 1000) {
            echo "⚠️  性能较慢 (500ms-1s)\n";
        } else {
            echo "❌ 性能仍然较慢 (> 1s)\n";
        }
        
        // 计算性能提升
        if ($avgTime < $previousAvgTime) {
            $improvement = round(($previousAvgTime - $avgTime) / $previousAvgTime * 100, 1);
            $timeSaved = round($previousAvgTime - $avgTime, 2);
            
            echo "\n📈 性能提升对比:\n";
            echo "- 优化前平均时间: " . number_format($previousAvgTime, 2) . " ms\n";
            echo "- 优化后平均时间: " . number_format($avgTime, 2) . " ms\n";
            echo "- 性能提升: {$improvement}%\n";
            echo "- 时间节省: {$timeSaved} ms\n";
            
            if ($improvement > 80) {
                echo "🎉 优化效果显著！\n";
            } elseif ($improvement > 50) {
                echo "👍 优化效果良好！\n";
            } elseif ($improvement > 20) {
                echo "✨ 有一定优化效果\n";
            } else {
                echo "🤔 优化效果有限，需要进一步分析\n";
            }
        }
        
        // 分析优化策略的效果
        echo "\n🔍 优化策略分析:\n";
        echo "1. ✅ 数据库预过滤: 减少候选账号数量\n";
        echo "2. ✅ 数据库预排序: 避免内存中复杂排序\n";
        echo "3. ✅ 早期退出机制: 找到合适账号立即返回\n";
        echo "4. ✅ 日志优化: 减少不必要的日志输出\n";
        echo "5. ✅ 批量查询优化: 减少数据库查询次数\n";
    }
    
    if (!empty($failedResults)) {
        echo "\n❌ 失败原因分析:\n";
        foreach ($failedResults as $index => $result) {
            echo "- 第" . ($index + 1) . "次失败: " . $result['error'] . "\n";
        }
    }
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "优化性能测试完成！\n";
    
} catch (Exception $e) {
    echo "❌ 测试过程中发生错误: " . $e->getMessage() . "\n";
    echo "错误堆栈:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} 