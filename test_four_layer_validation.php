<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Gift\FindAccountService;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use Illuminate\Support\Facades\Log;

// 启动Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🏗️ 四层验证机制测试\n";
echo "================================\n\n";

try {
    // 测试用例定义
    $testCases = [
        [
            'name' => '第二层失败 - 总额度超限',
            'account_balance' => 1400.00,
            'gift_card_amount' => 200.00,
            'plan_total_amount' => 1500.00,
            'expected_layer' => 2,
            'expected_result' => false,
            'description' => '账号余额1400 + 礼品卡200 = 1600 > 计划总额1500'
        ],
        [
            'name' => '第三层失败 - 预留不符合倍数',
            'account_balance' => 1000.00,
            'gift_card_amount' => 575.00,
            'plan_total_amount' => 1500.00,
            'constraint_type' => 'multiple',
            'multiple_base' => 50,
            'expected_layer' => 3,
            'expected_result' => false,
            'description' => '充满需要500，超出75不是50的倍数'
        ],
        [
            'name' => '第四层失败 - 超出当日额度',
            'account_balance' => 1000.00,
            'gift_card_amount' => 200.00,
            'plan_total_amount' => 1500.00,
            'daily_limit' => 100.00,
            'float_amount' => 50.00,
            'daily_spent' => 0.00,
            'current_day' => 1,
            'plan_days' => 3,
            'constraint_type' => 'all',
            'expected_layer' => 4,
            'expected_result' => false,
            'description' => '当日限额150，但礼品卡200超出'
        ],
        [
            'name' => '最后一天跳过第四层验证',
            'account_balance' => 1000.00,
            'gift_card_amount' => 200.00,
            'plan_total_amount' => 1500.00,
            'daily_limit' => 100.00,
            'float_amount' => 50.00,
            'daily_spent' => 0.00,
            'current_day' => 3,
            'plan_days' => 3,
            'constraint_type' => 'all',
            'expected_layer' => 4,
            'expected_result' => true,
            'description' => '最后一天跳过每日验证，直接通过'
        ],
        [
            'name' => '四层全部通过',
            'account_balance' => 1000.00,
            'gift_card_amount' => 150.00,
            'plan_total_amount' => 1500.00,
            'daily_limit' => 200.00,
            'float_amount' => 50.00,
            'daily_spent' => 0.00,
            'current_day' => 1,
            'plan_days' => 3,
            'constraint_type' => 'all',
            'expected_layer' => 4,
            'expected_result' => true,
            'description' => '所有层验证都通过'
        ]
    ];
    
    $roomId = '52443441973@chatroom';
    $planId = 5; // 使用实际的计划ID
    
    // 获取测试计划
    $plan = ItunesTradePlan::with('rate')->find($planId);
    if (!$plan) {
        echo "❌ 测试计划不存在 (ID: {$planId})\n";
        exit(1);
    }
    
    echo "📋 测试计划信息:\n";
    echo "   - 计划ID: {$plan->id}\n";
    echo "   - 四层验证机制测试\n\n";
    
    // 初始化服务
    $findAccountService = new FindAccountService();
    
    echo "✅ 服务初始化成功\n\n";
    
    // 运行测试用例
    $passedTests = 0;
    $totalTests = count($testCases);
    
    foreach ($testCases as $index => $testCase) {
        echo "🧪 测试用例 " . ($index + 1) . ": {$testCase['name']}\n";
        echo "   描述: {$testCase['description']}\n";
        
        // 模拟账号数据
        $mockAccountData = (object)[
            'id' => 999,
            'account' => 'test@example.com',
            'amount' => $testCase['account_balance'],
            'plan_id' => $plan->id,
            'current_plan_day' => $testCase['current_day'] ?? 1,
            'daily_spent' => $testCase['daily_spent'] ?? 0
        ];
        
        // 模拟礼品卡信息
        $giftCardInfo = [
            'amount' => $testCase['gift_card_amount'],
            'country_code' => 'CA',
            'currency' => 'USD'
        ];
        
        // 模拟汇率配置
        $mockRate = new ItunesTradeRate();
        $mockRate->amount_constraint = $testCase['constraint_type'] ?? 'all';
        
        if (isset($testCase['multiple_base'])) {
            $mockRate->multiple_base = $testCase['multiple_base'];
            $mockRate->min_amount = 50;
        }
        
        // 临时替换计划配置
        $originalRate = $plan->rate;
        $originalTotalAmount = $plan->total_amount;
        $originalDailyAmounts = $plan->daily_amounts;
        $originalFloatAmount = $plan->float_amount;
        $originalPlanDays = $plan->plan_days;
        
        $plan->setRelation('rate', $mockRate);
        $plan->total_amount = $testCase['plan_total_amount'];
        
        if (isset($testCase['daily_limit'])) {
            $plan->daily_amounts = [$testCase['daily_limit']];
        }
        if (isset($testCase['float_amount'])) {
            $plan->float_amount = $testCase['float_amount'];
        }
        if (isset($testCase['plan_days'])) {
            $plan->plan_days = $testCase['plan_days'];
        }
        
        try {
            // 使用反射调用私有方法进行测试
            $reflection = new ReflectionClass($findAccountService);
            $method = $reflection->getMethod('validateAccountConstraints');
            $method->setAccessible(true);
            
            $result = $method->invoke($findAccountService, $mockAccountData, $plan, $giftCardInfo);
            
            // 验证结果
            if ($result === $testCase['expected_result']) {
                echo "   ✅ 测试通过 (预期: " . ($testCase['expected_result'] ? '通过' : '失败') . ", 实际: " . ($result ? '通过' : '失败') . ")\n";
                $passedTests++;
            } else {
                echo "   ❌ 测试失败 (预期: " . ($testCase['expected_result'] ? '通过' : '失败') . ", 实际: " . ($result ? '通过' : '失败') . ")\n";
            }
            
        } catch (Exception $e) {
            echo "   ❌ 测试异常: " . $e->getMessage() . "\n";
        } finally {
            // 恢复原始配置
            $plan->setRelation('rate', $originalRate);
            $plan->total_amount = $originalTotalAmount;
            $plan->daily_amounts = $originalDailyAmounts;
            $plan->float_amount = $originalFloatAmount;
            $plan->plan_days = $originalPlanDays;
        }
        
        echo "\n";
    }
    
    // 测试总结
    echo "📊 测试总结:\n";
    echo "   - 总测试数: {$totalTests}\n";
    echo "   - 通过测试: {$passedTests}\n";
    echo "   - 失败测试: " . ($totalTests - $passedTests) . "\n";
    echo "   - 通过率: " . round(($passedTests / $totalTests) * 100, 1) . "%\n\n";
    
    if ($passedTests === $totalTests) {
        echo "🎉 所有测试通过！四层验证机制正常工作！\n";
    } else {
        echo "⚠️  部分测试失败，需要检查四层验证机制。\n";
    }
    
    // 实际场景测试
    echo "\n🔍 实际场景测试...\n";
    
    $realGiftCardInfo = [
        'amount' => 500.00,
        'country_code' => 'CA',
        'currency' => 'USD'
    ];
    
    echo "使用真实计划配置测试 500元 礼品卡...\n";
    
    $startTime = microtime(true);
    $account = $findAccountService->findAvailableAccount($plan, $roomId, $realGiftCardInfo, 1, 3);
    $endTime = microtime(true);
    
    $executionTime = ($endTime - $startTime) * 1000;
    
    if ($account) {
        echo "✅ 找到符合四层验证的账号:\n";
        echo "   - 账号: {$account->account}\n";
        echo "   - 余额: {$account->amount}\n";
        echo "   - 状态: {$account->status}\n";
        echo "   - 查找耗时: " . round($executionTime, 2) . " ms\n";
    } else {
        echo "❌ 未找到符合四层验证的账号\n";
        echo "   - 查找耗时: " . round($executionTime, 2) . " ms\n";
    }
    
    echo "\n🎯 四层验证机制测试完成！\n";
    
} catch (Exception $e) {
    echo "❌ 测试过程发生异常: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} 