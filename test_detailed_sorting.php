<?php

require_once 'vendor/autoload.php';

use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccount;
use App\Services\Gift\FindAccountService;
use Illuminate\Support\Facades\DB;

// 初始化Laravel应用
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== 详细排序逻辑测试 ===\n";

// 测试参数
$planId         = 1;
$roomId         = '46321584173@chatroom';
$giftCardAmount = 150;
$country        = 'CA';

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

// 获取基础符合条件的账号
$baseAccountIds = $findAccountService->getBaseQualifiedAccountIds($plan, $giftCardAmount, $country);
echo "基础条件筛选结果: " . count($baseAccountIds) . " 个账号\n\n";

// 直接执行排序SQL来验证逻辑
$placeholders = str_repeat('?,', count($baseAccountIds) - 1) . '?';
$sql          = "
    SELECT a.*,
           CASE
               WHEN a.plan_id = ? THEN 1
               WHEN a.plan_id IS NULL THEN 2
               ELSE 3
           END as binding_priority,
           CASE
               WHEN (a.amount + ?) = ? THEN 3
               WHEN (a.amount + ?) < ? THEN 2
               ELSE 1
           END as capacity_priority,
           COALESCE(l.exchange_time, '1970-01-01 00:00:00') as last_exchange_time
    FROM itunes_trade_accounts a
    LEFT JOIN (
        SELECT account_id, MAX(exchange_time) as exchange_time
        FROM itunes_trade_account_logs
        WHERE exchange_time IS NOT NULL
        GROUP BY account_id
    ) l ON a.id = l.account_id
    WHERE a.id IN ($placeholders)
      AND a.deleted_at IS NULL
    ORDER BY
        binding_priority ASC,
        capacity_priority DESC,
        a.amount DESC,
        last_exchange_time ASC,
        a.id ASC
    LIMIT 20
";

$params = [
    $plan->id,          // WHEN a.plan_id = ? THEN 1
    $giftCardAmount,    // WHEN (a.amount + ?) = ? THEN 3
    $plan->total_amount,// = ?
    $giftCardAmount,    // WHEN (a.amount + ?) < ? THEN 2
    $plan->total_amount // < ?
];
$params = array_merge($params, $baseAccountIds);

$result = DB::select($sql, $params);

echo "=== 排序结果（前20个） ===\n";
foreach ($result as $index => $account) {
    $afterExchange = $account->amount + $giftCardAmount;
    echo ($index + 1) . ". {$account->account} (ID:{$account->id}): 余额{$account->amount}, 兑换后{$afterExchange}, 绑定优先级{$account->binding_priority}, 容量优先级{$account->capacity_priority}, 最后兑换时间: {$account->last_exchange_time}\n";
}

// 检查目标账号
echo "\n=== 目标账号检查 ===\n";
$targetAccount = null;
foreach ($result as $index => $account) {
    if ($account->account === 'ferrispatrick369612@gmail.com') {
        $targetAccount = $account;
        echo "找到目标账号在排序第" . ($index + 1) . "位:\n";
        echo "- 账号: {$account->account}\n";
        echo "- 余额: {$account->amount}\n";
        echo "- 兑换后总额: " . ($account->amount + $giftCardAmount) . "\n";
        echo "- 绑定优先级: {$account->binding_priority}\n";
        echo "- 容量优先级: {$account->capacity_priority}\n";
        echo "- 最后兑换时间: {$account->last_exchange_time}\n";
        break;
    }
}

if (!$targetAccount) {
    echo "❌ 目标账号不在前20位中\n";
}

// 检查时间排序是否正确
echo "\n=== 时间排序验证 ===\n";
$capacity3Accounts = array_filter($result, function ($account) use ($giftCardAmount, $plan) {
    return ($account->amount + $giftCardAmount) == $plan->total_amount;
});

echo "容量优先级3的账号（按兑换时间排序）:\n";
foreach ($capacity3Accounts as $index => $account) {
    echo ($index + 1) . ". {$account->account}: {$account->last_exchange_time}\n";
}

echo "\n=== 测试完成 ===\n";
