<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Gift\GiftCardService;
use App\Services\GiftCardExchangeService;
use App\Services\Gift\FindAccountService;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

// 启动Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 FindAccountService集成测试\n";
echo "================================\n\n";

try {
    // 1. 测试数据
    $giftCardInfo = [
        'amount' => 500.00,
        'country_code' => 'CA',
        'currency' => 'USD',
        'valid' => true
    ];
    
    $roomId = 'test_room_001';
    $planId = 1; // 假设存在的计划ID
    
    // 2. 获取测试计划
    $plan = ItunesTradePlan::with('rate')->find($planId);
    if (!$plan) {
        echo "❌ 测试计划不存在 (ID: {$planId})\n";
        exit(1);
    }
    
    echo "📋 测试计划信息:\n";
    echo "   - 计划ID: {$plan->id}\n";
    echo "   - 总额度: {$plan->total_amount}\n";
    echo "   - 计划天数: {$plan->plan_days}\n";
    echo "   - 浮动额度: {$plan->float_amount}\n";
    echo "   - 汇率ID: {$plan->rate_id}\n\n";
    
    // 3. 初始化服务
    $exchangeService = new GiftCardExchangeService();
    $findAccountService = new FindAccountService();
    $giftCardService = new GiftCardService($exchangeService, $findAccountService);
    
    echo "✅ 服务初始化成功\n\n";
    
    // 4. 执行性能测试
    $testCount = 5;
    $executionTimes = [];
    $successCount = 0;
    $failureCount = 0;
    
    echo "🚀 开始执行{$testCount}次集成测试...\n\n";
    
    for ($i = 1; $i <= $testCount; $i++) {
        echo "第{$i}次测试: ";
        
        $startTime = microtime(true);
        
        try {
            // 直接调用FindAccountService
            $account = $findAccountService->findAvailableAccount($plan, $roomId, $giftCardInfo);
            
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;
            $executionTimes[] = $executionTime;
            
            if ($account) {
                echo "✅ 成功 - 找到账号: {$account->account} (余额: {$account->amount}) ";
                echo "耗时: " . round($executionTime, 2) . " ms\n";
                $successCount++;
                
                // 验证账号状态
                if ($account->status === 'locking') {
                    echo "   ✅ 账号已正确锁定\n";
                } else {
                    echo "   ⚠️  账号状态异常: {$account->status}\n";
                }
                
            } else {
                echo "❌ 失败 - 未找到可用账号 ";
                echo "耗时: " . round($executionTime, 2) . " ms\n";
                $failureCount++;
            }
            
        } catch (Exception $e) {
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;
            $executionTimes[] = $executionTime;
            
            echo "❌ 异常 - {$e->getMessage()} ";
            echo "耗时: " . round($executionTime, 2) . " ms\n";
            $failureCount++;
        }
        
        // 短暂延迟避免并发冲突
        usleep(100000); // 100ms
    }
    
    echo "\n📊 集成测试结果统计:\n";
    echo "- 成功次数: {$successCount} / {$testCount}\n";
    echo "- 失败次数: {$failureCount} / {$testCount}\n\n";
    
    if (!empty($executionTimes)) {
        $avgTime = array_sum($executionTimes) / count($executionTimes);
        $minTime = min($executionTimes);
        $maxTime = max($executionTimes);
        $timeRange = $maxTime - $minTime;
        
        echo "⚡ 执行时间统计:\n";
        echo "- 平均时间: " . round($avgTime, 2) . " ms\n";
        echo "- 最快时间: " . round($minTime, 2) . " ms\n";
        echo "- 最慢时间: " . round($maxTime, 2) . " ms\n";
        echo "- 时间范围: " . round($timeRange, 2) . " ms\n\n";
        
        // 性能等级评估
        if ($avgTime < 50) {
            $performanceLevel = "🥇 性能等级: S (优秀)";
        } elseif ($avgTime < 200) {
            $performanceLevel = "🥈 性能等级: A (良好)";
        } elseif ($avgTime < 500) {
            $performanceLevel = "🥉 性能等级: B (一般)";
        } else {
            $performanceLevel = "😞 性能等级: C (需要优化)";
        }
        
        echo "📊 性能等级评估:\n";
        echo $performanceLevel . "\n\n";
    }
    
    // 5. 测试GiftCardService集成
    echo "🔗 测试GiftCardService集成...\n";
    
    // 设置礼品卡服务参数
    $giftCardService->setGiftCardCode('test_card_12345')
                   ->setRoomId($roomId)
                   ->setCardType('iTunes')
                   ->setCardForm('code')
                   ->setBatchId('test_batch_001')
                   ->setMsgId('test_msg_001')
                   ->setWxId('test_wx_001');
    
    echo "✅ GiftCardService参数设置完成\n";
    echo "   - 礼品卡码: test_card_12345\n";
    echo "   - 房间ID: {$roomId}\n";
    echo "   - 卡类型: iTunes\n";
    echo "   - 批次ID: test_batch_001\n\n";
    
    // 6. 查看账号查找统计信息
    echo "📈 账号查找统计信息:\n";
    $statistics = $findAccountService->getSearchStatistics($plan, $roomId);
    
    echo "   状态分布:\n";
    foreach ($statistics['status_distribution'] as $status => $count) {
        echo "   - {$status}: {$count} 个\n";
    }
    
    echo "   计划绑定分布:\n";
    foreach ($statistics['plan_binding_distribution'] as $type => $count) {
        echo "   - {$type}: {$count} 个\n";
    }
    
    echo "   - 总可用账号: {$statistics['total_available']} 个\n";
    echo "   - 统计时间: {$statistics['timestamp']}\n\n";
    
    echo "🎉 FindAccountService集成测试完成！\n";
    
} catch (Exception $e) {
    echo "❌ 测试过程发生异常: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} 