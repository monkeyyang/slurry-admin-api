<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccount;
use Illuminate\Support\Facades\DB;

echo "========================================\n";
echo "账号 #154 筛选失败原因诊断\n";
echo "========================================\n";

try {
    // 初始化Laravel应用
    if (file_exists(__DIR__ . '/bootstrap/app.php')) {
        $app = require_once __DIR__ . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    }

    $accountId = 154;
    $planId = 1;
    $giftCardAmount = 200;

    // 获取账号信息
    echo "📋 账号 #{$accountId} 信息：\n";
    $account = ItunesTradeAccount::withTrashed()->find($accountId);

    if (!$account) {
        echo "❌ 账号不存在！\n";
        exit(1);
    }

    echo "  - 余额：{$account->amount}\n";
    echo "  - 状态：{$account->status}\n";
    echo "  - 登录状态：{$account->login_status}\n";
    echo "  - 绑定计划：" . ($account->plan_id ?? 'NULL') . "\n";
    echo "  - 绑定房间：" . ($account->room_id ?? 'NULL') . "\n";
    echo "  - 当前天数：" . ($account->current_plan_day ?? 'NULL') . " 🚨\n";
    echo "  - 每日限额：{$account->daily_amounts}\n\n";

    // 获取计划信息
    echo "📋 计划 #{$planId} 信息：\n";
    $plan = ItunesTradePlan::with('rate')->find($planId);

    if (!$plan) {
        echo "❌ 计划不存在！\n";
        exit(1);
    }

    echo "  - 总额度：{$plan->total_amount}\n";
    echo "  - 计划天数：{$plan->plan_days}\n";
    echo "  - 每日限额：" . (is_array($plan->daily_amounts) ? json_encode($plan->daily_amounts) : $plan->daily_amounts) . "\n";
    echo "  - 浮动金额：{$plan->float_amount}\n";
    echo "  - 绑定群聊：" . ($plan->bind_room ? '是' : '否') . "\n\n";

        // 完整的5层筛选验证
    echo "🔍 完整5层筛选验证：\n";

    // 第1层：基础条件筛选
    $totalAfterExchange = $account->amount + $giftCardAmount;
    echo "  1️⃣ 第1层-基础条件筛选：\n";
    echo "     - 状态检查：{$account->status} " . ($account->status === 'processing' ? '✅' : '❌') . "\n";
    echo "     - 登录检查：{$account->login_status} " . ($account->login_status === 'valid' ? '✅' : '❌') . "\n";
    echo "     - 国家检查：{$account->country_code} vs CA " . ($account->country_code === 'CA' ? '✅' : '❌') . "\n";
    echo "     - 余额检查：{$account->amount} >= 0 " . ($account->amount >= 0 ? '✅' : '❌') . "\n";
    echo "     - 总额检查：{$totalAfterExchange} <= {$plan->total_amount} " . ($totalAfterExchange <= $plan->total_amount ? '✅' : '❌') . "\n";

    $layer1Pass = ($account->status === 'processing') &&
                  ($account->login_status === 'valid') &&
                  ($account->country_code === 'CA') &&
                  ($account->amount >= 0) &&
                  ($totalAfterExchange <= $plan->total_amount);

    echo "     📊 第1层结果：" . ($layer1Pass ? '✅ 通过' : '❌ 失败') . "\n\n";

    if (!$layer1Pass) {
        echo "🚨 筛选在第1层失败，无需继续检查。\n";
        return;
    }

    // 第2层：约束条件筛选
    echo "  2️⃣ 第2层-约束条件筛选：\n";
    $layer2Pass = true;

    // 获取汇率信息（通过计划关联）
    $rate = $plan->rate;
    if ($rate) {
        $constraintType = $rate->amount_constraint;
        echo "     - 约束类型：{$constraintType}\n";

        if ($constraintType === 'multiple') {
            $multipleBase = $rate->multiple_base ?? 50;
            $minAmount = $rate->min_amount ?? 150;
            $maxAmount = $rate->max_amount ?? 500;

            $isMultiple = ($giftCardAmount % $multipleBase == 0);
            $isAboveMin = ($giftCardAmount >= $minAmount);
            $isBelowMax = ($giftCardAmount <= $maxAmount);

            echo "     - 倍数检查：{$giftCardAmount} % {$multipleBase} == 0 " . ($isMultiple ? '✅' : '❌') . "\n";
            echo "     - 最小值检查：{$giftCardAmount} >= {$minAmount} " . ($isAboveMin ? '✅' : '❌') . "\n";
            echo "     - 最大值检查：{$giftCardAmount} <= {$maxAmount} " . ($isBelowMax ? '✅' : '❌') . "\n";

            $layer2Pass = $isMultiple && $isAboveMin && $isBelowMax;
        } elseif ($constraintType === 'fixed') {
            $fixedAmounts = $rate->fixed_amounts ?? [];
            if (is_string($fixedAmounts)) {
                $fixedAmounts = json_decode($fixedAmounts, true) ?: [];
            }
            echo "     - 固定面额：" . json_encode($fixedAmounts) . "\n";
            $layer2Pass = in_array($giftCardAmount, $fixedAmounts);
            echo "     - 面额匹配：{$giftCardAmount} in " . json_encode($fixedAmounts) . " " . ($layer2Pass ? '✅' : '❌') . "\n";
        }
    } else {
        echo "     - 无汇率约束，自动通过 ✅\n";
    }

    echo "     📊 第2层结果：" . ($layer2Pass ? '✅ 通过' : '❌ 失败') . "\n\n";

    if (!$layer2Pass) {
        echo "🚨 筛选在第2层失败，无需继续检查。\n";
        return;
    }

    // 第3层：群聊绑定筛选
    echo "  3️⃣ 第3层-群聊绑定筛选：\n";
    $bindRoom = $plan->bind_room ?? false;
    echo "     - 计划要求绑定群聊：" . ($bindRoom ? '是' : '否') . "\n";

    $layer3Pass = true;
    if ($bindRoom) {
        $accountRoomId = $account->room_id;
        $testRoomId = '50165570842@chatroom';

        echo "     - 账号当前房间：" . ($accountRoomId ?: '未绑定') . "\n";
        echo "     - 测试房间：{$testRoomId}\n";

        $canBind = is_null($accountRoomId) || ($accountRoomId === $testRoomId);
        echo "     - 可以绑定：" . ($canBind ? '✅' : '❌') . "\n";

        $layer3Pass = $canBind;
    } else {
        echo "     - 无需绑定群聊，自动通过 ✅\n";
    }

    echo "     📊 第3层结果：" . ($layer3Pass ? '✅ 通过' : '❌ 失败') . "\n\n";

    if (!$layer3Pass) {
        echo "🚨 筛选在第3层失败：账号已绑定到其他房间！\n";
        return;
    }

    // 第4层：容量检查筛选（预留逻辑）
    echo "  4️⃣ 第4层-容量检查筛选（预留逻辑）：\n";
    $currentBalance = $account->amount;
    $totalPlanAmount = $plan->total_amount;
    $afterExchangeAmount = $currentBalance + $giftCardAmount;

    echo "     - 当前余额：{$currentBalance}\n";
    echo "     - 礼品卡金额：{$giftCardAmount}\n";
    echo "     - 兑换后金额：{$afterExchangeAmount}\n";
    echo "     - 计划总额：{$totalPlanAmount}\n";

    // 检查是否正好充满
    $canFillCompletely = abs($afterExchangeAmount - $totalPlanAmount) < 0.01;
    echo "     - 正好充满检查：" . ($canFillCompletely ? '✅ 可以充满' : '❌ 不能充满') . "\n";

    $layer4Pass = false;

    if ($canFillCompletely) {
        $layer4Pass = true;
        echo "     - 🎯 容量验证：充满逻辑通过 ✅\n";
    } else {
        // 检查预留逻辑
        $remainingSpace = $totalPlanAmount - $currentBalance - $giftCardAmount;
        echo "     - 剩余空间：{$remainingSpace}\n";

        if ($rate) {
            $constraintType = $rate->amount_constraint;
            echo "     - 预留约束类型：{$constraintType}\n";

            switch ($constraintType) {
                case 'multiple':
                    $multipleBase = $rate->multiple_base ?? 50;
                    $minAmount = $rate->min_amount ?? 150;
                    $A = max($multipleBase, $minAmount);

                    $condition1 = ($remainingSpace >= $A);
                    $condition2 = ($remainingSpace % $multipleBase == 0);

                    echo "     - A = max({$multipleBase}, {$minAmount}) = {$A}\n";
                    echo "     - 条件1：剩余空间 >= A ({$remainingSpace} >= {$A}) " . ($condition1 ? '✅' : '❌') . "\n";
                    echo "     - 条件2：剩余空间 % 倍数基数 == 0 ({$remainingSpace} % {$multipleBase} == 0) " . ($condition2 ? '✅' : '❌') . "\n";

                    $layer4Pass = $condition1 && $condition2;
                    break;

                case 'fixed':
                    $fixedAmounts = $rate->fixed_amounts ?? [];
                    if (is_string($fixedAmounts)) {
                        $fixedAmounts = json_decode($fixedAmounts, true) ?: [];
                    }
                    $minFixedAmount = min($fixedAmounts);

                    $condition = ($remainingSpace >= $minFixedAmount);
                    echo "     - 最小面额：{$minFixedAmount}\n";
                    echo "     - 条件：剩余空间 >= 最小面额 ({$remainingSpace} >= {$minFixedAmount}) " . ($condition ? '✅' : '❌') . "\n";

                    $layer4Pass = $condition;
                    break;

                case 'all':
                    $layer4Pass = true;
                    echo "     - 全面额约束：任何剩余空间都可以 ✅\n";
                    break;

                default:
                    $layer4Pass = true;
                    echo "     - 未知约束类型，允许 ✅\n";
                    break;
            }
        } else {
            $layer4Pass = true;
            echo "     - 无汇率约束，允许任何剩余空间 ✅\n";
        }
    }

    echo "     📊 第4层结果：" . ($layer4Pass ? '✅ 通过' : '❌ 失败') . "\n\n";

    if (!$layer4Pass) {
        echo "🚨 筛选在第4层失败：容量检查不符合预留逻辑！\n";
        return;
    }

    // 第5层：每日计划限制筛选
    $currentDay = $account->current_plan_day ?? 1;
    echo "  5️⃣ 第5层-每日计划限制筛选：\n";
    echo "     - 账号当前天数：{$currentDay}\n";
    echo "     - 计划总天数：{$plan->plan_days}\n";

    $validationDay = $account->plan_id ? $currentDay : 1;
    echo "     - 验证使用天数：{$validationDay}\n";

    $isLastDay = $validationDay >= $plan->plan_days;
    echo "     - 是否最后一天：" . ($isLastDay ? '是' : '否') . "\n";

    $layer5Pass = true;

    if ($isLastDay) {
        echo "     - 🎯 最后一天跳过每日验证 ✅\n";
    } else {
        // 检查每日限额
        $dailyAmounts = is_array($plan->daily_amounts) ? $plan->daily_amounts : (json_decode($plan->daily_amounts, true) ?? []);
        $dailyLimit = $dailyAmounts[$validationDay - 1] ?? 0;
        $dailyTarget = $dailyLimit + $plan->float_amount;

        echo "     - 第{$validationDay}天限额：{$dailyLimit}\n";
        echo "     - 第{$validationDay}天目标：{$dailyTarget}\n";

        // 查询当天已兑换金额
        $dailySpent = DB::select("
            SELECT COALESCE(SUM(amount), 0) as daily_spent
            FROM itunes_trade_account_logs
            WHERE account_id = ? AND day = ? AND status = 'success'
        ", [$accountId, $validationDay]);

        $spentAmount = $dailySpent[0]->daily_spent ?? 0;
        $remainingAmount = $dailyTarget - $spentAmount;

        echo "     - 第{$validationDay}天已兑换：{$spentAmount}\n";
        echo "     - 第{$validationDay}天剩余：{$remainingAmount}\n";
        echo "     - 礼品卡金额：{$giftCardAmount}\n";
        echo "     - 条件：礼品卡 <= 剩余额度 ({$giftCardAmount} <= {$remainingAmount}) " . ($giftCardAmount <= $remainingAmount ? '✅' : '❌') . "\n";

        $layer5Pass = ($giftCardAmount <= $remainingAmount);
    }

    echo "     📊 第5层结果：" . ($layer5Pass ? '✅ 通过' : '❌ 失败') . "\n\n";

    if (!$layer5Pass) {
        echo "🚨 筛选在第5层失败：每日限额不足！\n";
        return;
    }

    echo "🎉 所有5层筛选均通过！账号应该能被找到。\n";
    echo "\n";

    // 3. 建议修复方案
    echo "🔧 建议修复方案：\n";
    if ($account->current_plan_day > $plan->plan_days) {
        echo "  1. 重置账号天数：UPDATE itunes_trade_accounts SET current_plan_day = 1 WHERE id = {$accountId};\n";
    }
    if ($account->plan_id && $account->plan_id != $planId) {
        echo "  2. 清理绑定关系：UPDATE itunes_trade_accounts SET plan_id = NULL, room_id = NULL WHERE id = {$accountId};\n";
    }
    if ($totalAfterExchange > $plan->total_amount) {
        echo "  3. 增加计划总额度或降低账号余额\n";
    }

} catch (Exception $e) {
    echo "❌ 诊断过程中发生异常：{$e->getMessage()}\n";
}

echo "\n========================================\n";
echo "诊断完成\n";
echo "========================================\n";
