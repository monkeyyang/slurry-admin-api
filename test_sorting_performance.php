<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap/app.php';

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use App\Services\Gift\RedeemService;
use Illuminate\Support\Facades\Log;

echo "开始排序性能测试...\n";

try {
    // 1. 获取测试数据
    $accounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
        ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
        ->limit(1000) // 限制测试数据量
        ->get();
    
    if ($accounts->isEmpty()) {
        echo "没有找到可用的测试账号\n";
        exit(1);
    }
    
    $plan = ItunesTradePlan::with('rate')->where('status', ItunesTradePlan::STATUS_ENABLED)->first();
    
    if (!$plan) {
        echo "没有找到可用的测试计划\n";
        exit(1);
    }
    
    $roomId = 'test_room_123';
    $giftCardInfo = [
        'amount' => 100.00,
        'country_code' => 'US',
        'currency' => 'USD'
    ];
    
    echo "测试数据准备完成:\n";
    echo "- 账号数量: {$accounts->count()}\n";
    echo "- 计划ID: {$plan->id}\n";
    echo "- 礼品卡金额: {$giftCardInfo['amount']}\n";
    echo "- 国家代码: {$giftCardInfo['country_code']}\n";
    echo "\n";
    
    // 2. 创建测试服务
    $redeemService = new RedeemService();
    
    // 3. 执行性能测试
    echo "执行排序性能测试...\n";
    $testResults = $redeemService->testSortingPerformance($accounts, $plan, $roomId, $giftCardInfo);
    
    // 4. 输出测试结果
    echo "\n=== 排序性能测试结果 ===\n";
    echo str_repeat("=", 80) . "\n";
    
    $methods = ['original', 'precomputed', 'layered', 'database'];
    $results = [];
    
    foreach ($methods as $method) {
        if (isset($testResults[$method])) {
            $result = $testResults[$method];
            $results[] = $result;
            
            echo sprintf(
                "方法: %-30s | 耗时: %8.2f ms | 账号数: %4d | 首个账号ID: %s\n",
                $result['method'],
                $result['time_ms'],
                $result['account_count'],
                $result['first_account_id'] ?? 'N/A'
            );
            
            // 如果是分层排序，显示额外信息
            if ($method === 'layered' && isset($result['layer_counts'])) {
                echo "    └─ 分层统计: ";
                echo "能充满({$result['layer_counts']['layer_1_can_fill']}) | ";
                echo "可预留({$result['layer_counts']['layer_2_can_reserve']}) | ";
                echo "不适合({$result['layer_counts']['layer_3_not_suitable']})\n";
            }
        }
    }
    
    echo str_repeat("=", 80) . "\n";
    
    // 5. 性能分析
    if (count($results) > 1) {
        // 找出最快和最慢的方法
        usort($results, function($a, $b) {
            return $a['time_ms'] <=> $b['time_ms'];
        });
        
        $fastest = $results[0];
        $slowest = $results[count($results) - 1];
        
        echo "\n=== 性能分析 ===\n";
        echo "最快方法: {$fastest['method']} ({$fastest['time_ms']} ms)\n";
        echo "最慢方法: {$slowest['method']} ({$slowest['time_ms']} ms)\n";
        
        if ($slowest['time_ms'] > 0) {
            $improvement = round(($slowest['time_ms'] - $fastest['time_ms']) / $slowest['time_ms'] * 100, 1);
            echo "性能提升: {$improvement}%\n";
        }
        
        // 推荐使用的方法
        echo "\n=== 推荐方案 ===\n";
        if ($fastest['time_ms'] < 100) {
            echo "推荐使用: {$fastest['method']}\n";
            echo "原因: 性能最佳，耗时 {$fastest['time_ms']} ms\n";
        } else {
            echo "所有方法耗时都较长，建议进一步优化\n";
        }
    }
    
    // 6. 多次测试取平均值（可选）
    echo "\n=== 稳定性测试 ===\n";
    echo "执行5次测试取平均值...\n";
    
    $avgResults = [];
    $testCount = 5;
    
    for ($i = 0; $i < $testCount; $i++) {
        $results = $redeemService->testSortingPerformance($accounts, $plan, $roomId, $giftCardInfo);
        
        foreach ($results as $method => $result) {
            if (!isset($avgResults[$method])) {
                $avgResults[$method] = ['times' => [], 'method' => $result['method']];
            }
            $avgResults[$method]['times'][] = $result['time_ms'];
        }
        
        echo "第 " . ($i + 1) . " 次测试完成\n";
    }
    
    echo "\n=== 平均性能结果 ===\n";
    echo str_repeat("=", 80) . "\n";
    
    foreach ($avgResults as $method => $data) {
        $times = $data['times'];
        $avg = array_sum($times) / count($times);
        $min = min($times);
        $max = max($times);
        $std = sqrt(array_sum(array_map(function($x) use ($avg) { return pow($x - $avg, 2); }, $times)) / count($times));
        
        echo sprintf(
            "方法: %-30s | 平均: %8.2f ms | 最小: %8.2f ms | 最大: %8.2f ms | 标准差: %6.2f\n",
            $data['method'],
            $avg,
            $min,
            $max,
            $std
        );
    }
    
    echo str_repeat("=", 80) . "\n";
    echo "测试完成!\n";
    
} catch (Exception $e) {
    echo "测试过程中发生错误: " . $e->getMessage() . "\n";
    echo "错误堆栈:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} 