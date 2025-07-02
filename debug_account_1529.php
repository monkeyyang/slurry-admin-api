<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Gift\FindAccountService;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccount;
use Illuminate\Support\Facades\DB;

echo "========================================\n";
echo "账号 #1529 筛选失败原因诊断\n";
echo "========================================\n";

try {
    // 初始化Laravel应用
    if (file_exists(__DIR__ . '/bootstrap/app.php')) {
        $app = require_once __DIR__ . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    }
    
    // 测试参数
    $accountId = 1529;
    $testParams = [
        'country' => 'CA',
        'amount' => 200,
        'plan_id' => 1,
        'room_id' => '50165570842@chatroom',
        'current_day' => 1
    ];
    
    echo "🎯 测试参数：\n";
    echo "  - 账号ID：{$accountId}\n";
    echo "  - 国家：{$testParams['country']}\n";
    echo "  - 礼品卡金额：{$testParams['amount']}\n";
    echo "  - 计划ID：{$testParams['plan_id']}\n";
    echo "  - 房间ID：{$testParams['room_id']}\n\n";
    
    // 1. 获取账号详细信息
    echo "📋 账号 #{$accountId} 详细信息：\n";
    $account = ItunesTradeAccount::find($accountId);
    
    if (!$account) {
        echo "❌ 账号不存在！\n";
        exit(1);
    }
    
    echo "  - 邮箱：{$account->account}\n";
    echo "  - 国家：{$account->country_code}\n";
    echo "  - 余额：{$account->amount}\n";
    echo "  - 状态：{$account->status}\n";
    echo "  - 登录状态：{$account->login_status}\n";
    echo "  - 绑定计划：" . ($account->plan_id ? "#{$account->plan_id}" : '未绑定') . "\n";
    echo "  - 绑定房间：" . ($account->room_id ? $account->room_id : '未绑定') . "\n";
    echo "  - 当前天数：" . ($account->current_plan_day ?? 1) . "\n";
    echo "  - 每日限额：{$account->daily_amounts}\n\n";
    
    // 2. 获取测试计划信息
    echo "📋 测试计划 #{$testParams['plan_id']} 信息：\n";
    $plan = ItunesTradePlan::with('rate')->find($testParams['plan_id']);
    
    if (!$plan) {
        echo "❌ 计划不存在！\n";
        exit(1);
    }
    
    echo "  - 计划名称：{$plan->name}\n";
    echo "  - 计划国家：{$plan->country}\n";
    echo "  - 总额度：{$plan->total_amount}\n";
    echo "  - 绑定群聊：" . ($plan->bind_room ? '是' : '否') . "\n";
    
    if ($plan->rate) {
        echo "  - 汇率约束：{$plan->rate->amount_constraint}\n";
        if ($plan->rate->amount_constraint === 'multiple') {
            echo "    * 倍数基数：{$plan->rate->multiple_base}\n";
            echo "    * 最小金额：{$plan->rate->min_amount}\n";
        }
    }
    echo "\n";
    
    // 3. 创建服务并逐层诊断
    $findAccountService = new FindAccountService();
    $giftCardInfo = [
        'amount' => $testParams['amount'],
        'country' => $testParams['country'],
        'room_id' => $testParams['room_id']
    ];
    
    echo "🔍 逐层筛选诊断：\n";
    
    // 第1层：基础条件
    echo "  📊 第1层-基础条件筛选：\n";
    $totalAfterExchange = $account->amount + $testParams['amount'];
    echo "    - 状态检查：{$account->status} " . ($account->status === 'processing' ? '✅' : '❌') . "\n";
    echo "    - 登录检查：{$account->login_status} " . ($account->login_status === 'valid' ? '✅' : '❌') . "\n";
    echo "    - 国家检查：{$account->country_code} vs {$testParams['country']} " . ($account->country_code === $testParams['country'] ? '✅' : '❌') . "\n";
    echo "    - 余额检查：{$account->amount} >= 0 " . ($account->amount >= 0 ? '✅' : '❌') . "\n";
    echo "    - 总额检查：{$totalAfterExchange} <= {$plan->total_amount} " . ($totalAfterExchange <= $plan->total_amount ? '✅' : '❌') . "\n";
    
    $layer1Pass = ($account->status === 'processing') && 
                  ($account->login_status === 'valid') && 
                  ($account->country_code === $testParams['country']) && 
                  ($account->amount >= 0) && 
                  ($totalAfterExchange <= $plan->total_amount);
    
    echo "    📊 第1层结果：" . ($layer1Pass ? '✅ 通过' : '❌ 失败') . "\n\n";
    
    if (!$layer1Pass) {
        echo "🚨 筛选在第1层失败，无需继续检查后续层级。\n";
        exit(0);
    }
    
    // 第2层：约束条件
    echo "  📊 第2层-约束条件筛选：\n";
    $layer2Pass = true;
    
    if ($plan->rate) {
        $constraintType = $plan->rate->amount_constraint;
        echo "    - 约束类型：{$constraintType}\n";
        
        if ($constraintType === 'multiple') {
            $multipleBase = $plan->rate->multiple_base ?? 50;
            $minAmount = $plan->rate->min_amount ?? 150;
            $maxAmount = $plan->rate->max_amount ?? 500;
            
            $isMultiple = ($testParams['amount'] % $multipleBase == 0);
            $isAboveMin = ($testParams['amount'] >= $minAmount);
            $isBelowMax = ($testParams['amount'] <= $maxAmount);
            
            echo "    - 倍数检查：{$testParams['amount']} % {$multipleBase} == 0 " . ($isMultiple ? '✅' : '❌') . "\n";
            echo "    - 最小值检查：{$testParams['amount']} >= {$minAmount} " . ($isAboveMin ? '✅' : '❌') . "\n";
            echo "    - 最大值检查：{$testParams['amount']} <= {$maxAmount} " . ($isBelowMax ? '✅' : '❌') . "\n";
            
            $layer2Pass = $isMultiple && $isAboveMin && $isBelowMax;
        } elseif ($constraintType === 'fixed') {
            $fixedAmounts = $plan->rate->fixed_amounts ?? [];
            if (is_string($fixedAmounts)) {
                $fixedAmounts = json_decode($fixedAmounts, true) ?: [];
            }
            echo "    - 固定面额：" . json_encode($fixedAmounts) . "\n";
            echo "    - 面额匹配：{$testParams['amount']} in " . json_encode($fixedAmounts);
            $layer2Pass = in_array($testParams['amount'], $fixedAmounts);
            echo " " . ($layer2Pass ? '✅' : '❌') . "\n";
        }
    } else {
        echo "    - 无汇率约束，自动通过 ✅\n";
    }
    
    echo "    📊 第2层结果：" . ($layer2Pass ? '✅ 通过' : '❌ 失败') . "\n\n";
    
    if (!$layer2Pass) {
        echo "🚨 筛选在第2层失败，无需继续检查后续层级。\n";
        exit(0);
    }
    
    // 第3层：群聊绑定
    echo "  📊 第3层-群聊绑定筛选：\n";
    $bindRoom = $plan->bind_room ?? false;
    echo "    - 计划要求绑定群聊：" . ($bindRoom ? '是' : '否') . "\n";
    
    $layer3Pass = true;
    if ($bindRoom) {
        $accountRoomId = $account->room_id;
        $testRoomId = $testParams['room_id'];
        
        echo "    - 账号当前房间：" . ($accountRoomId ?: '未绑定') . "\n";
        echo "    - 测试房间：{$testRoomId}\n";
        
        $canBind = is_null($accountRoomId) || ($accountRoomId === $testRoomId);
        echo "    - 可以绑定：" . ($canBind ? '✅' : '❌') . "\n";
        
        $layer3Pass = $canBind;
    } else {
        echo "    - 无需绑定群聊，自动通过 ✅\n";
    }
    
    echo "    📊 第3层结果：" . ($layer3Pass ? '✅ 通过' : '❌ 失败') . "\n\n";
    
    if (!$layer3Pass) {
        echo "🚨 筛选在第3层失败！\n";
        echo "🔧 失败原因：账号已绑定到房间 {$account->room_id}，无法绑定到测试房间 {$testParams['room_id']}\n";
        exit(0);
    }
    
    // 继续其他层级的检查...
    echo "🎉 前3层检查均通过，账号应该能被找到。\n";
    echo "🔍 如果仍然找不到，请检查第4层(容量)和第5层(每日计划)的具体逻辑。\n";
    
} catch (Exception $e) {
    echo "❌ 诊断过程中发生异常：{$e->getMessage()}\n";
    echo "堆栈跟踪：\n{$e->getTraceAsString()}\n";
}

echo "\n========================================\n";
echo "诊断完成\n";
echo "========================================\n"; 