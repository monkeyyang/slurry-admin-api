<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Gift\FindAccountService;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use Illuminate\Support\Facades\Log;

// 启动Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧠 智能预留判断功能测试\n";
echo "================================\n\n";

try {
    // 测试用例定义
    $testCases = [
        [
            'name' => '倍数约束 - 可以充满计划',
            'gift_card_amount' => 100.00,
            'constraint_type' => 'multiple',
            'multiple_base' => 50,
            'min_amount' => 50,
            'expected_result' => true,
            'description' => '100元可以完全用于当日计划，无需预留'
        ],
        [
            'name' => '倍数约束 - 需要预留150',
            'gift_card_amount' => 650.00,
            'constraint_type' => 'multiple',
            'multiple_base' => 50,
            'min_amount' => 50,
            'expected_result' => true,
            'description' => '650元兑换600元后，预留50元不足150最小要求，需要预留150元'
        ],
        [
            'name' => '倍数约束 - 预留金额不符合倍数',
            'gift_card_amount' => 675.00,
            'constraint_type' => 'multiple',
            'multiple_base' => 50,
            'min_amount' => 50,
            'expected_result' => false,
            'description' => '675元兑换600元后，预留75元不是50的倍数'
        ],
        [
            'name' => '固定面额约束 - 可以预留50',
            'gift_card_amount' => 650.00,
            'constraint_type' => 'fixed',
            'fixed_amounts' => [50, 100],
            'expected_result' => true,
            'description' => '650元兑换600元后，预留50元符合固定面额要求'
        ],
        [
            'name' => '固定面额约束 - 预留金额不匹配',
            'gift_card_amount' => 675.00,
            'constraint_type' => 'fixed',
            'fixed_amounts' => [50, 100],
            'expected_result' => false,
            'description' => '675元兑换600元后，预留75元不匹配任何固定面额'
        ],
        [
            'name' => '全面额约束 - 可以预留任意金额',
            'gift_card_amount' => 675.00,
            'constraint_type' => 'all',
            'expected_result' => true,
            'description' => '675元兑换600元后，预留75元符合全面额要求'
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
    echo "   - 总额度: {$plan->total_amount}\n";
    echo "   - 当日额度: 600 (假设)\n";
    echo "   - 浮动额度: {$plan->float_amount}\n";
    echo "   - 当日目标: 700 (600+100)\n\n";
    
    // 初始化服务
    $findAccountService = new FindAccountService();
    
    echo "✅ 服务初始化成功\n\n";
    
    // 运行测试用例
    $passedTests = 0;
    $totalTests = count($testCases);
    
    foreach ($testCases as $index => $testCase) {
        echo "🧪 测试用例 " . ($index + 1) . ": {$testCase['name']}\n";
        echo "   描述: {$testCase['description']}\n";
        echo "   礼品卡金额: {$testCase['gift_card_amount']}\n";
        
        // 模拟账号数据
        $mockAccountData = (object)[
            'id' => 999,
            'account' => 'test@example.com',
            'amount' => 1000.00,
            'plan_id' => $plan->id,
            'current_plan_day' => 1,
            'daily_spent' => 0 // 当日已兑换0元
        ];
        
        // 模拟礼品卡信息
        $giftCardInfo = [
            'amount' => $testCase['gift_card_amount'],
            'country_code' => 'CA',
            'currency' => 'USD'
        ];
        
        // 模拟汇率配置
        $mockRate = new ItunesTradeRate();
        $mockRate->amount_constraint = $testCase['constraint_type'];
        
        if ($testCase['constraint_type'] === 'multiple') {
            $mockRate->multiple_base = $testCase['multiple_base'];
            $mockRate->min_amount = $testCase['min_amount'];
        } elseif ($testCase['constraint_type'] === 'fixed') {
            $mockRate->fixed_amounts = json_encode($testCase['fixed_amounts']);
        }
        
        // 临时替换计划的汇率
        $originalRate = $plan->rate;
        $plan->setRelation('rate', $mockRate);
        
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
            // 恢复原始汇率
            $plan->setRelation('rate', $originalRate);
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
        echo "🎉 所有测试通过！智能预留判断功能正常工作！\n";
    } else {
        echo "⚠️  部分测试失败，需要检查智能预留判断逻辑。\n";
    }
    
    // 实际场景测试
    echo "\n🔍 实际场景测试...\n";
    
    $realGiftCardInfo = [
        'amount' => 500.00,
        'country_code' => 'CA',
        'currency' => 'USD'
    ];
    
    echo "使用真实计划和汇率配置测试 500元 礼品卡...\n";
    
    $startTime = microtime(true);
    $account = $findAccountService->findAvailableAccount($plan, $roomId, $realGiftCardInfo, 1, 3);
    $endTime = microtime(true);
    
    $executionTime = ($endTime - $startTime) * 1000;
    
    if ($account) {
        echo "✅ 找到符合智能预留条件的账号:\n";
        echo "   - 账号: {$account->account}\n";
        echo "   - 余额: {$account->amount}\n";
        echo "   - 状态: {$account->status}\n";
        echo "   - 查找耗时: " . round($executionTime, 2) . " ms\n";
    } else {
        echo "❌ 未找到符合智能预留条件的账号\n";
        echo "   - 查找耗时: " . round($executionTime, 2) . " ms\n";
    }
    
    echo "\n🎯 智能预留判断功能测试完成！\n";
    
} catch (Exception $e) {
    echo "❌ 测试过程发生异常: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} 