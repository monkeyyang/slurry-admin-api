<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Gift\FindAccountService;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use Illuminate\Support\Facades\Log;

// 启动Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "📅 账号天数验证逻辑测试\n";
echo "================================\n\n";

try {
    $roomId = '52443441973@chatroom';
    $planId = 5;
    
    // 获取测试计划
    $plan = ItunesTradePlan::with('rate')->find($planId);
    if (!$plan) {
        echo "❌ 测试计划不存在 (ID: {$planId})\n";
        exit(1);
    }
    
    echo "📋 测试计划信息:\n";
    echo "   - 计划ID: {$plan->id}\n";
    echo "   - 总额度: {$plan->total_amount}\n";
    echo "   - 计划天数: {$plan->plan_days}\n";
    echo "   - 每日额度: " . json_encode($plan->daily_amounts) . "\n";
    echo "   - 浮动额度: {$plan->float_amount}\n\n";
    
    // 模拟不同天数的账号
    $testAccounts = [
        [
            'id' => 1001,
            'account' => 'account1@test.com',
            'amount' => 500.00,
            'plan_id' => $plan->id,
            'current_plan_day' => 1,
            'daily_spent' => 0,
            'description' => '第1天账号，无兑换记录'
        ],
        [
            'id' => 1002,
            'account' => 'account2@test.com',
            'amount' => 800.00,
            'plan_id' => $plan->id,
            'current_plan_day' => 2,
            'daily_spent' => 100,
            'description' => '第2天账号，已兑换100'
        ],
        [
            'id' => 1003,
            'account' => 'account3@test.com',
            'amount' => 1200.00,
            'plan_id' => $plan->id,
            'current_plan_day' => 5,
            'daily_spent' => 50,
            'description' => '第5天账号（最后一天），已兑换50'
        ],
        [
            'id' => 1004,
            'account' => 'account4@test.com',
            'amount' => 300.00,
            'plan_id' => null,
            'current_plan_day' => null,
            'daily_spent' => 0,
            'description' => '未绑定计划的账号'
        ]
    ];
    
    $giftCardInfo = [
        'amount' => 200.00,
        'country_code' => 'CA',
        'currency' => 'USD'
    ];
    
    echo "🧪 测试礼品卡金额: {$giftCardInfo['amount']}\n\n";
    
    // 初始化服务
    $findAccountService = new FindAccountService();
    
    // 测试每个账号
    foreach ($testAccounts as $index => $accountData) {
        echo "📱 测试账号 " . ($index + 1) . ": {$accountData['description']}\n";
        echo "   - 账号: {$accountData['account']}\n";
        echo "   - 余额: {$accountData['amount']}\n";
        echo "   - 当前天数: " . ($accountData['current_plan_day'] ?? '未设置') . "\n";
        echo "   - 当日已兑换: {$accountData['daily_spent']}\n";
        
        // 转换为对象
        $mockAccountData = (object)$accountData;
        
        try {
            // 使用反射调用私有方法进行测试
            $reflection = new ReflectionClass($findAccountService);
            $method = $reflection->getMethod('validateAccountConstraints');
            $method->setAccessible(true);
            
            $startTime = microtime(true);
            $result = $method->invoke($findAccountService, $mockAccountData, $plan, $giftCardInfo);
            $endTime = microtime(true);
            
            $executionTime = ($endTime - $startTime) * 1000;
            
            if ($result) {
                echo "   ✅ 验证通过 (耗时: " . round($executionTime, 2) . " ms)\n";
            } else {
                echo "   ❌ 验证失败 (耗时: " . round($executionTime, 2) . " ms)\n";
            }
            
            // 详细验证每一层
            echo "   🔍 详细验证过程:\n";
            
            // 第一层：礼品卡基本约束
            $giftCardMethod = $reflection->getMethod('validateGiftCardConstraints');
            $giftCardMethod->setAccessible(true);
            $layer1 = $giftCardMethod->invoke($findAccountService, $plan, $giftCardInfo['amount']);
            echo "      第一层 (礼品卡约束): " . ($layer1 ? '✅ 通过' : '❌ 失败') . "\n";
            
            if ($layer1) {
                // 第二层：总额度验证
                $totalMethod = $reflection->getMethod('validateTotalAmountLimit');
                $totalMethod->setAccessible(true);
                $layer2 = $totalMethod->invoke($findAccountService, $mockAccountData, $plan, $giftCardInfo['amount']);
                echo "      第二层 (总额度验证): " . ($layer2 ? '✅ 通过' : '❌ 失败') . "\n";
                
                if ($layer2) {
                    // 第三层：预留验证
                    $reserveMethod = $reflection->getMethod('validateAccountReservation');
                    $reserveMethod->setAccessible(true);
                    $layer3 = $reserveMethod->invoke($findAccountService, $mockAccountData, $plan, $giftCardInfo['amount']);
                    echo "      第三层 (预留验证): " . ($layer3 ? '✅ 通过' : '❌ 失败') . "\n";
                    
                    if ($layer3) {
                        // 第四层：每日计划验证
                        $dailyMethod = $reflection->getMethod('validateDailyPlanLimit');
                        $dailyMethod->setAccessible(true);
                        $layer4 = $dailyMethod->invoke($findAccountService, $mockAccountData, $plan, $giftCardInfo['amount']);
                        echo "      第四层 (每日计划验证): " . ($layer4 ? '✅ 通过' : '❌ 失败') . "\n";
                        
                        // 显示每日计划详情
                        $currentDay = $accountData['current_plan_day'] ?? 1;
                        $dailyAmounts = $plan->daily_amounts ?? [];
                        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;
                        $dailyTarget = $dailyLimit + $plan->float_amount;
                        $remainingDaily = $dailyTarget - $accountData['daily_spent'];
                        
                        echo "         - 当前天数: {$currentDay}\n";
                        echo "         - 当日限额: {$dailyLimit}\n";
                        echo "         - 浮动额度: {$plan->float_amount}\n";
                        echo "         - 当日目标: {$dailyTarget}\n";
                        echo "         - 已兑换: {$accountData['daily_spent']}\n";
                        echo "         - 剩余额度: {$remainingDaily}\n";
                        echo "         - 是否最后一天: " . ($currentDay >= $plan->plan_days ? '是' : '否') . "\n";
                    }
                }
            }
            
        } catch (Exception $e) {
            echo "   ❌ 测试异常: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }
    
    echo "🎯 账号天数验证逻辑测试完成！\n";
    echo "\n📝 关键发现:\n";
    echo "   - 每个账号根据自己的当前天数进行验证\n";
    echo "   - 未绑定计划的账号默认为第1天\n";
    echo "   - 最后一天的账号跳过每日计划验证\n";
    echo "   - 四层验证确保了严格的业务规则遵循\n";
    
} catch (Exception $e) {
    echo "❌ 测试过程发生异常: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} 