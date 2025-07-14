<?php

require_once 'vendor/autoload.php';

use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccount;
use App\Services\Gift\FindAccountService;
use Illuminate\Support\Facades\DB;

// 初始化Laravel应用
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== 排序逻辑修复测试（按兑换时间排序） ===\n";

// 测试参数
$planId         = 1;
$roomId         = '46321584173@chatroom';
$giftCardAmount = 150;
$country        = 'CA';
$currentDay     = 1;

// 获取计划信息
$plan = ItunesTradePlan::find($planId);
if (!$plan) {
    echo "❌ 计划不存在: $planId\n";
    exit(1);
}

echo "计划信息:\n";
echo "- 计划ID: {$plan->id}\n";
echo "- 总额度: {$plan->total_amount}\n";
echo "- 绑定房间: " . ($plan->bind_room ? '是' : '否') . "\n\n";

// 创建服务实例
$findAccountService = new FindAccountService();

// 测试参数
$giftCardInfo = [
    'amount'       => $giftCardAmount,
    'country_code' => $country,
    'room_id'      => $roomId
];

echo "测试参数:\n";
echo "- 礼品卡金额: $giftCardAmount\n";
echo "- 国家: $country\n";
echo "- 房间ID: $roomId\n\n";

// 获取基础符合条件的账号
$baseAccountIds = $findAccountService->getBaseQualifiedAccountIds($plan, $giftCardAmount, $country);
echo "基础条件筛选结果: " . count($baseAccountIds) . " 个账号\n\n";

if (empty($baseAccountIds)) {
    echo "❌ 没有符合条件的账号\n";
    exit(1);
}

// 获取前20个账号的详细信息（包括兑换时间）
$accounts = DB::table('itunes_trade_accounts as a')
    ->leftJoin(DB::raw('(SELECT account_id, MAX(exchange_time) as exchange_time FROM itunes_trade_account_logs WHERE exchange_time IS NOT NULL GROUP BY account_id) as l'), 'a.id', '=', 'l.account_id')
    ->whereIn('a.id', array_slice($baseAccountIds, 0, 20))
    ->select('a.id', 'a.account', 'a.amount', 'a.plan_id', 'a.room_id', 'a.updated_at', DB::raw('COALESCE(l.exchange_time, "1970-01-01 00:00:00") as last_exchange_time'))
    ->orderBy('a.amount', 'desc')
    ->get();

echo "=== 前20个账号信息（按余额降序） ===\n";
foreach ($accounts as $account) {
    $afterExchange    = $account->amount + $giftCardAmount;
    $bindingPriority  = $account->plan_id == $plan->id ? 1 : 2;
    $capacityPriority = $afterExchange == $plan->total_amount ? 3 : ($afterExchange < $plan->total_amount ? 2 : 1);

    echo "- {$account->account} (ID:{$account->id}): 余额{$account->amount}, 兑换后{$afterExchange}, 绑定优先级{$bindingPriority}, 容量优先级{$capacityPriority}, 最后兑换时间: {$account->last_exchange_time}\n";
}

// 测试排序逻辑
echo "\n=== 排序逻辑测试 ===\n";
$sortedAccountIds = $findAccountService->sortAccountsByPriority($baseAccountIds, $plan, $roomId, $giftCardAmount);

if (empty($sortedAccountIds)) {
    echo "❌ 排序失败\n";
    exit(1);
}

// 获取排序后的前10个账号（包括兑换时间）
$topAccounts = DB::table('itunes_trade_accounts as a')
    ->leftJoin(DB::raw('(SELECT account_id, MAX(exchange_time) as exchange_time FROM itunes_trade_account_logs WHERE exchange_time IS NOT NULL GROUP BY account_id) as l'), 'a.id', '=', 'l.account_id')
    ->whereIn('a.id', array_slice($sortedAccountIds, 0, 10))
    ->select('a.id', 'a.account', 'a.amount', 'a.plan_id', 'a.room_id', 'a.updated_at', DB::raw('COALESCE(l.exchange_time, "1970-01-01 00:00:00") as last_exchange_time'))
    ->orderByRaw('FIELD(a.id, ' . implode(',', array_slice($sortedAccountIds, 0, 10)) . ')')
    ->get();

echo "排序后的前10个账号:\n";
foreach ($topAccounts as $index => $account) {
    $afterExchange    = $account->amount + $giftCardAmount;
    $bindingPriority  = $account->plan_id == $plan->id ? 1 : 2;
    $capacityPriority = $afterExchange == $plan->total_amount ? 3 : ($afterExchange < $plan->total_amount ? 2 : 1);

    echo ($index + 1) . ". {$account->account} (ID:{$account->id}): 余额{$account->amount}, 兑换后{$afterExchange}, 绑定优先级{$bindingPriority}, 容量优先级{$capacityPriority}, 最后兑换时间: {$account->last_exchange_time}\n";
}

// 检查目标账号
$targetAccount = DB::table('itunes_trade_accounts as a')
    ->leftJoin(DB::raw('(SELECT account_id, MAX(exchange_time) as exchange_time FROM itunes_trade_account_logs WHERE exchange_time IS NOT NULL GROUP BY account_id) as l'), 'a.id', '=', 'l.account_id')
    ->where('a.account', 'ferrispatrick369612@gmail.com')
    ->select('a.id', 'a.account', 'a.amount', 'a.plan_id', 'a.room_id', 'a.updated_at', DB::raw('COALESCE(l.exchange_time, "1970-01-01 00:00:00") as last_exchange_time'))
    ->first();

if ($targetAccount) {
    echo "\n=== 目标账号检查 ===\n";
    $afterExchange    = $targetAccount->amount + $giftCardAmount;
    $bindingPriority  = $targetAccount->plan_id == $plan->id ? 1 : 2;
    $capacityPriority = $afterExchange == $plan->total_amount ? 3 : ($afterExchange < $plan->total_amount ? 2 : 1);

    echo "目标账号 {$targetAccount->account}:\n";
    echo "- 余额: {$targetAccount->amount}\n";
    echo "- 兑换后总额: {$afterExchange}\n";
    echo "- 绑定优先级: {$bindingPriority}\n";
    echo "- 容量优先级: {$capacityPriority}\n";
    echo "- 计划ID: {$targetAccount->plan_id}\n";
    echo "- 房间ID: {$targetAccount->room_id}\n";
    echo "- 最后兑换时间: {$targetAccount->last_exchange_time}\n";

    // 检查目标账号在排序中的位置
    $targetPosition = array_search($targetAccount->id, $sortedAccountIds);
    if ($targetPosition !== false) {
        echo "- 在排序中的位置: " . ($targetPosition + 1) . "\n";
        if ($targetPosition < 10) {
            echo "✅ 目标账号在排序前10位\n";
        } else {
            echo "⚠️ 目标账号在排序第" . ($targetPosition + 1) . "位\n";
        }
    } else {
        echo "❌ 目标账号不在排序结果中\n";
    }
}

echo "\n=== 测试完成 ===\n";
