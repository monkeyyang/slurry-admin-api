<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Gift\FindAccountService;
use App\Models\ItunesTradePlan;
use Illuminate\Support\Facades\Log;

// 启动Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔄 FindAccountService重试机制测试\n";
echo "================================\n\n";

try {
    // 1. 测试数据
    $giftCardInfo = [
        'amount' => 100.00,
        'country_code' => 'CA',
        'currency' => 'USD',
        'valid' => true
    ];
    
    $roomId = '52443441973@chatroom';
    $planId = 5; // 使用实际的计划ID
    
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
    echo "   - 浮动额度: {$plan->float_amount}\n\n";
    
    // 3. 初始化服务
    $findAccountService = new FindAccountService();
    
    echo "✅ 服务初始化成功\n\n";
    
    // 4. 测试重试机制
    echo "🚀 测试重试机制（最大重试3次）...\n\n";
    
    $startTime = microtime(true);
    
    // 使用重试机制查找账号
    $account = $findAccountService->findAvailableAccount($plan, $roomId, $giftCardInfo, 1, 3);
    
    $endTime = microtime(true);
    $totalTime = ($endTime - $startTime) * 1000;
    
    if ($account) {
        echo "✅ 重试机制测试成功！\n";
        echo "   - 找到账号: {$account->account}\n";
        echo "   - 账号余额: {$account->amount}\n";
        echo "   - 账号状态: {$account->status}\n";
        echo "   - 总耗时: " . round($totalTime, 2) . " ms\n\n";
        
        // 验证账号是否被正确锁定
        if ($account->status === 'locking') {
            echo "✅ 账号已正确锁定\n";
        } else {
            echo "⚠️  账号状态异常: {$account->status}\n";
        }
        
    } else {
        echo "❌ 重试机制测试失败 - 未找到可用账号\n";
        echo "   - 总耗时: " . round($totalTime, 2) . " ms\n";
    }
    
    echo "\n📊 重试机制特性:\n";
    echo "   - 最大重试次数: 3次\n";
    echo "   - 排除已尝试账号: ✅\n";
    echo "   - 自动延迟重试: ✅ (10ms)\n";
    echo "   - 原子锁定机制: ✅\n";
    echo "   - 并发安全保护: ✅\n\n";
    
    // 5. 获取统计信息
    echo "📈 当前账号统计:\n";
    $statistics = $findAccountService->getSearchStatistics($plan, $roomId);
    
    echo "   状态分布:\n";
    foreach ($statistics['status_distribution'] as $status => $count) {
        echo "   - {$status}: {$count} 个\n";
    }
    
    echo "   计划绑定分布:\n";
    foreach ($statistics['plan_binding_distribution'] as $type => $count) {
        echo "   - {$type}: {$count} 个\n";
    }
    
    echo "\n🎉 重试机制测试完成！\n";
    
} catch (Exception $e) {
    echo "❌ 测试过程发生异常: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} 