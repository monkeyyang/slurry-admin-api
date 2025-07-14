<?php

require_once 'vendor/autoload.php';

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccountLog;
use Illuminate\Support\Facades\DB;

// 初始化Laravel应用
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== 账号优先级诊断 ===\n";

// 目标账号信息
$targetAccount  = 'ferrispatrick369612@gmail.com';
$giftCardAmount = 150.00;

echo "目标账号: {$targetAccount}\n";
echo "礼品卡金额: {$giftCardAmount}\n\n";

// 获取账号信息
$account = ItunesTradeAccount::where('account', $targetAccount)->first();

if (!$account) {
    echo "❌ 账号不存在\n";
    exit;
}

echo "账号信息:\n";
echo "- ID: {$account->id}\n";
echo "- 状态: {$account->status}\n";
echo "- 登录状态: {$account->login_status}\n";
echo "- 余额: {$account->amount}\n";
echo "- 计划ID: {$account->plan_id}\n";
echo "- 当前天数: {$account->current_plan_day}\n";
echo "- 国家: {$account->country_code}\n";
echo "- 房间ID: {$account->room_id}\n\n";

// 获取计划信息
$plan = ItunesTradePlan::find($account->plan_id);
if ($plan) {
    echo "计划信息:\n";
    echo "- 计划ID: {$plan->id}\n";
    echo "- 总额度: {$plan->total_amount}\n";
    echo "- 浮动额度: {$plan->float_amount}\n";
    echo "- 每日额度: " . json_encode($plan->daily_amounts) . "\n";
    echo "- 绑定房间: " . ($plan->bind_room ? '是' : '否') . "\n\n";
} else {
    echo "❌ 计划不存在\n";
    exit;
}

// 计算优先级
$currentBalance     = $account->amount;
$totalAfterExchange = $currentBalance + $giftCardAmount;

echo "容量计算:\n";
echo "- 当前余额: {$currentBalance}\n";
echo "- 兑换后总额: {$totalAfterExchange}\n";
echo "- 计划总额度: {$plan->total_amount}\n";

// 计算capacity_priority
$capacityPriority = 1;
if (abs($totalAfterExchange - $plan->total_amount) < 0.01) {
    $capacityPriority = 3; // 正好充满
    echo "- 容量优先级: 3 (正好充满计划额度)\n";
} elseif ($totalAfterExchange < $plan->total_amount) {
    $capacityPriority = 2; // 可以预留
    echo "- 容量优先级: 2 (可以预留)\n";
} else {
    $capacityPriority = 1; // 超出计划额度
    echo "- 容量优先级: 1 (超出计划额度)\n";
}

// 检查每日计划限制
$currentDay   = $account->current_plan_day ?? 1;
$dailyAmounts = $plan->daily_amounts ?? [];
$dailyLimit   = $dailyAmounts[$currentDay - 1] ?? 0;
$dailySpent   = ItunesTradeAccountLog::where('account_id', $account->id)
    ->where('day', $currentDay)
    ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
    ->sum('amount');

$maxDailyAmount       = $dailyLimit + ($plan->float_amount ?? 0);
$remainingDailyAmount = $maxDailyAmount - $dailySpent;

echo "\n每日计划检查:\n";
echo "- 当前天数: {$currentDay}\n";
echo "- 当日限额: {$dailyLimit}\n";
echo "- 浮动额度: {$plan->float_amount}\n";
echo "- 当日最大可兑换: {$maxDailyAmount}\n";
echo "- 当日已兑换: {$dailySpent}\n";
echo "- 当日剩余可兑换: {$remainingDailyAmount}\n";

if ($giftCardAmount <= $remainingDailyAmount) {
    echo "- ✅ 当日计划验证通过\n";
} else {
    echo "- ❌ 当日计划验证失败\n";
}

// 检查最近的兑换记录
echo "\n=== 最近兑换记录 ===\n";
$recentLogs = ItunesTradeAccountLog::where('account_id', $account->id)
    ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

echo "最近5次成功兑换:\n";
foreach ($recentLogs as $log) {
    echo "- {$log->created_at}: 金额{$log->amount}, 房间{$log->room_id}, 礼品卡{$log->code}\n";
}

// 检查是否有待处理的兑换记录
echo "\n=== 待处理兑换记录 ===\n";
$pendingLogs = ItunesTradeAccountLog::where('account_id', $account->id)
    ->where('status', ItunesTradeAccountLog::STATUS_PENDING)
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

echo "待处理兑换记录:\n";
foreach ($pendingLogs as $log) {
    echo "- {$log->created_at}: 金额{$log->amount}, 房间{$log->room_id}, 礼品卡{$log->code}\n";
}

// 检查群聊绑定逻辑
echo "\n=== 群聊绑定分析 ===\n";
$accountRoomId = $account->room_id;
echo "账号绑定的房间ID: {$accountRoomId}\n";

// 查找所有使用该房间ID的兑换记录
$roomLogs = ItunesTradeAccountLog::where('room_id', $accountRoomId)
    ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

echo "该房间的最近兑换记录:\n";
foreach ($roomLogs as $log) {
    $logAccount = ItunesTradeAccount::find($log->account_id);
    echo "- {$log->created_at}: 账号{$logAccount->account}, 金额{$log->amount}, 礼品卡{$log->code}\n";
}

// 查找同计划的其他账号
echo "\n=== 同计划账号对比 ===\n";
$samePlanAccounts = ItunesTradeAccount::where('plan_id', $account->plan_id)
    ->where('status', 'processing')
    ->where('login_status', 'valid')
    ->where('country_code', $account->country_code)
    ->where('id', '!=', $account->id)
    ->orderBy('amount', 'desc')
    ->limit(10)
    ->get();

echo "同计划账号 (前10个):\n";
foreach ($samePlanAccounts as $otherAccount) {
    $otherTotalAfter       = $otherAccount->amount + $giftCardAmount;
    $otherCapacityPriority = 1;
    if (abs($otherTotalAfter - $plan->total_amount) < 0.01) {
        $otherCapacityPriority = 3;
    } elseif ($otherTotalAfter < $plan->total_amount) {
        $otherCapacityPriority = 2;
    }

    echo "- {$otherAccount->account} (ID:{$otherAccount->id}): 余额{$otherAccount->amount}, 兑换后{$otherTotalAfter}, 容量优先级{$otherCapacityPriority}\n";
}

// 查找所有可用账号
echo "\n=== 所有可用账号 (前20个) ===\n";
$allAvailableAccounts = ItunesTradeAccount::where('status', 'processing')
    ->where('login_status', 'valid')
    ->where('country_code', $account->country_code)
    ->orderBy('amount', 'desc')
    ->limit(20)
    ->get();

echo "所有可用账号:\n";
foreach ($allAvailableAccounts as $otherAccount) {
    $otherTotalAfter       = $otherAccount->amount + $giftCardAmount;
    $otherCapacityPriority = 1;
    if (abs($otherTotalAfter - $plan->total_amount) < 0.01) {
        $otherCapacityPriority = 3;
    } elseif ($otherTotalAfter < $plan->total_amount) {
        $otherCapacityPriority = 2;
    }

    $bindingPriority = 5;
    if ($otherAccount->plan_id == $plan->id && $otherAccount->room_id == $account->room_id) {
        $bindingPriority = 1;
    } elseif ($otherAccount->plan_id == $plan->id) {
        $bindingPriority = 2;
    } elseif ($otherAccount->room_id == $account->room_id) {
        $bindingPriority = 3;
    } elseif ($otherAccount->plan_id === null) {
        $bindingPriority = 4;
    }

    echo "- {$otherAccount->account} (ID:{$otherAccount->id}): 余额{$otherAccount->amount}, 计划{$otherAccount->plan_id}, 房间{$otherAccount->room_id}, 绑定优先级{$bindingPriority}, 容量优先级{$otherCapacityPriority}\n";
}

// 检查是否有其他账号被优先使用
echo "\n=== 检查其他账号使用情况 ===\n";
$recentSuccessfulLogs = ItunesTradeAccountLog::where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
    ->where('created_at', '>=', now()->subHours(2))
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

echo "最近2小时的成功兑换记录:\n";
foreach ($recentSuccessfulLogs as $log) {
    $logAccount = ItunesTradeAccount::find($log->account_id);
    if ($logAccount) {
        echo "- {$log->created_at}: 账号{$logAccount->account} (ID:{$log->account_id}), 金额{$log->amount}, 房间{$log->room_id}, 礼品卡{$log->code}\n";
    }
}

echo "\n=== 诊断完成 ===\n";
