<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Gift\GiftCardService;
use App\Services\GiftCardExchangeService;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 测试 GiftCardService 的 findAvailableAccount 方法
 */
echo "=== 测试 findAvailableAccount 方法 ===\n\n";

try {
    // 初始化 Laravel 应用
    $app = require_once __DIR__ . '/bootstrap/app.php';
    $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
    
    // 创建服务实例
    $giftCardApiClient = new App\Services\GiftCardApiClient();
    $exchangeService = new GiftCardExchangeService($giftCardApiClient);
    $giftCardService = new GiftCardService($exchangeService);
    
    echo "✅ 服务初始化成功\n\n";
    
    // 1. 显示数据库状态
    echo "=== 数据库状态 ===\n";
    
    $planCount = ItunesTradePlan::where('status', ItunesTradePlan::STATUS_ENABLED)->count();
    echo "可用计划数量: {$planCount}\n";
    
    $accountCounts = DB::table('itunes_trade_accounts')
        ->select('status', DB::raw('count(*) as count'))
        ->where('login_status', 'valid')
        ->groupBy('status')
        ->get();
        
    echo "账号状态统计:\n";
    foreach ($accountCounts as $status) {
        echo "  - {$status->status}: {$status->count}\n";
    }
    
    $rateCount = ItunesTradeRate::where('status', 'active')->count();
    echo "可用汇率数量: {$rateCount}\n\n";
    
    // 2. 获取测试数据
    $plan = ItunesTradePlan::where('status', ItunesTradePlan::STATUS_ENABLED)->first();
    if (!$plan) {
        throw new Exception("未找到可用的测试计划");
    }
    
    echo "=== 测试数据 ===\n";
    echo "使用计划: ID={$plan->id}, 总额度={$plan->total_amount}, 天数={$plan->plan_days}\n";
    
    // 显示计划的每日额度
    $dailyAmounts = $plan->daily_amounts ?? [];
    echo "每日额度: " . json_encode($dailyAmounts) . "\n";
    echo "浮动额度: {$plan->float_amount}\n";
    echo "绑定房间: " . ($plan->bind_room ? '是' : '否') . "\n\n";
    
    // 3. 测试不同的礼品卡金额
    $testCases = [
        ['amount' => 25.00, 'desc' => '小额测试'],
        ['amount' => 100.00, 'desc' => '中等金额测试'],
        ['amount' => 500.00, 'desc' => '大额测试'],
    ];
    
    $roomId = 'test_room_' . time();
    
    foreach ($testCases as $index => $testCase) {
        echo "=== 测试案例 " . ($index + 1) . ": {$testCase['desc']} ===\n";
        
        $giftCardInfo = [
            'amount' => $testCase['amount'],
            'country_code' => 'US',
            'currency' => 'USD'
        ];
        
        echo "礼品卡信息: 金额=\${$testCase['amount']}, 国家=US\n";
        echo "房间ID: {$roomId}\n";
        
        try {
            // 使用反射调用私有方法
            $reflection = new ReflectionClass($giftCardService);
            $method = $reflection->getMethod('findAvailableAccount');
            $method->setAccessible(true);
            
            $startTime = microtime(true);
            $account = $method->invoke($giftCardService, $plan, $roomId, $giftCardInfo);
            $endTime = microtime(true);
            
            $executionTime = round(($endTime - $startTime) * 1000, 2);
            
            echo "✅ 成功找到账号:\n";
            echo "  - 账号ID: {$account->id}\n";
            echo "  - 账号名: {$account->account}\n";
            echo "  - 状态: {$account->status}\n";
            echo "  - 余额: \${$account->amount}\n";
            echo "  - 计划ID: " . ($account->plan_id ?? 'null') . "\n";
            echo "  - 房间ID: " . ($account->room_id ?? 'null') . "\n";
            echo "  - 当前天数: " . ($account->current_plan_day ?? 'null') . "\n";
            echo "  - 执行时间: {$executionTime}ms\n";
            
            // 恢复账号状态（如果被锁定了）
            if ($account->status === ItunesTradeAccount::STATUS_LOCKING) {
                $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
                echo "  - 已恢复账号状态为 PROCESSING\n";
            }
            
        } catch (Exception $e) {
            echo "❌ 查找失败: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }
    
    // 4. 测试候选账号查询
    echo "=== 候选账号查询测试 ===\n";
    
    try {
        $reflection = new ReflectionClass($giftCardService);
        $getCandidatesMethod = $reflection->getMethod('getAllCandidateAccounts');
        $getCandidatesMethod->setAccessible(true);
        
        $startTime = microtime(true);
        $candidates = $getCandidatesMethod->invoke($giftCardService, $plan, $roomId);
        $endTime = microtime(true);
        
        $queryTime = round(($endTime - $startTime) * 1000, 2);
        
        echo "✅ 候选账号查询成功:\n";
        echo "  - 候选账号数量: " . $candidates->count() . "\n";
        echo "  - 查询时间: {$queryTime}ms\n";
        
        // 显示前5个候选账号
        echo "  - 前5个候选账号:\n";
        foreach ($candidates->take(5) as $index => $candidate) {
            echo "    " . ($index + 1) . ". ID={$candidate->id}, 余额=\${$candidate->amount}, ";
            echo "计划=" . ($candidate->plan_id ?? 'null') . ", ";
            echo "房间=" . ($candidate->room_id ?? 'null') . "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ 候选账号查询失败: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // 5. 测试账号排序
    echo "=== 账号排序测试 ===\n";
    
    try {
        $reflection = new ReflectionClass($giftCardService);
        $getCandidatesMethod = $reflection->getMethod('getAllCandidateAccounts');
        $getCandidatesMethod->setAccessible(true);
        $sortMethod = $reflection->getMethod('sortAccountsByPriority');
        $sortMethod->setAccessible(true);
        
        $candidates = $getCandidatesMethod->invoke($giftCardService, $plan, $roomId);
        
        if ($candidates->count() > 0) {
            $giftCardInfo = ['amount' => 100.00, 'country_code' => 'US', 'currency' => 'USD'];
            
            $startTime = microtime(true);
            $sortedAccounts = $sortMethod->invoke($giftCardService, $candidates, $plan, $roomId, $giftCardInfo);
            $endTime = microtime(true);
            
            $sortTime = round(($endTime - $startTime) * 1000, 2);
            
            echo "✅ 账号排序成功:\n";
            echo "  - 排序前数量: " . $candidates->count() . "\n";
            echo "  - 排序后数量: " . $sortedAccounts->count() . "\n";
            echo "  - 排序时间: {$sortTime}ms\n";
            
            echo "  - 排序后前5个账号:\n";
            foreach ($sortedAccounts->take(5) as $index => $account) {
                echo "    " . ($index + 1) . ". ID={$account->id}, 余额=\${$account->amount}, ";
                echo "计划=" . ($account->plan_id == $plan->id ? '当前' : ($account->plan_id ?? '无')) . ", ";
                echo "房间=" . ($account->room_id == $roomId ? '匹配' : ($account->room_id ?? '无')) . "\n";
            }
        } else {
            echo "⚠️  没有候选账号可供排序\n";
        }
        
    } catch (Exception $e) {
        echo "❌ 账号排序失败: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // 6. 测试容量验证
    echo "=== 容量验证测试 ===\n";
    
    $testAccount = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
        ->where('login_status', 'valid')
        ->first();
        
    if ($testAccount) {
        echo "使用测试账号: ID={$testAccount->id}, 余额=\${$testAccount->amount}\n";
        
        $testAmounts = [25, 50, 100, 200, 500];
        
        try {
            $reflection = new ReflectionClass($giftCardService);
            $validateMethod = $reflection->getMethod('validateAccountCapacity');
            $validateMethod->setAccessible(true);
            
            foreach ($testAmounts as $amount) {
                $giftCardInfo = [
                    'amount' => $amount,
                    'country_code' => 'US',
                    'currency' => 'USD'
                ];
                
                $isValid = $validateMethod->invoke($giftCardService, $testAccount, $plan, $giftCardInfo);
                $status = $isValid ? '✅ 通过' : '❌ 失败';
                echo "  - 金额 \${$amount}: {$status}\n";
            }
            
        } catch (Exception $e) {
            echo "❌ 容量验证测试失败: " . $e->getMessage() . "\n";
        }
    } else {
        echo "⚠️  未找到可用的测试账号\n";
    }
    
    echo "\n=== 测试完成 ===\n";
    
} catch (Exception $e) {
    echo "❌ 测试失败: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
} 