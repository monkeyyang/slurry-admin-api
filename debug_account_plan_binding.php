<?php

require_once 'vendor/autoload.php';

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Services\Gift\FindAccountService;
use Illuminate\Support\Facades\DB;

// 初始化Laravel应用
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== 调试1650优先逻辑 ===\n\n";

// 测试参数
$planId = 1;
$giftCardAmount = 150.00; // 面额150
$country = 'CA';

// 获取计划信息
$plan = ItunesTradePlan::find($planId);
if (!$plan) {
    echo "❌ 计划不存在: $planId\n";
    exit(1);
}

echo "测试参数:\n";
echo "- 计划ID: {$plan->id}\n";
echo "- 总额度: {$plan->total_amount}\n";
echo "- 礼品卡面额: {$giftCardAmount}\n";
echo "- 国家: {$country}\n\n";

// 创建服务实例
$findAccountService = new FindAccountService();

// 查找可能的1650账号
echo "查找可能的1650账号:\n";
echo "----------------------------------------\n";

$sql = "
    SELECT id, account, amount, plan_id, status, login_status, country_code, account_type
    FROM itunes_trade_accounts
    WHERE status = 'processing'
      AND login_status = 'valid'
      AND country_code = ?
      AND amount >= 0
      AND (amount + ?) <= ?
      AND deleted_at IS NULL
      AND account_type = ?
      AND (plan_id = ? OR plan_id IS NULL)
    ORDER BY amount DESC
    LIMIT 20
";

$params = [
    $country,
    $giftCardAmount,
    $plan->total_amount,
    'device',
    $plan->id
];

$accounts = DB::select($sql, $params);

$target1650Accounts = [];
$otherAccounts = [];

foreach ($accounts as $account) {
    $afterExchange = $account->amount + $giftCardAmount;
    
    if (abs($afterExchange - 1650) < 0.01) {
        $target1650Accounts[] = $account;
        echo "🎯 目标1650: {$account->account} - 余额:{$account->amount} + 面额:{$giftCardAmount} = {$afterExchange}\n";
    } elseif (abs($afterExchange - 1700) < 0.01) {
        echo "⚠️  会被排斥: {$account->account} - 余额:{$account->amount} + 面额:{$giftCardAmount} = {$afterExchange}\n";
    } else {
        $otherAccounts[] = $account;
        echo "其他: {$account->account} - 余额:{$account->amount} + 面额:{$giftCardAmount} = {$afterExchange}\n";
    }
}

echo "\n统计:\n";
echo "- 目标1650账号: " . count($target1650Accounts) . " 个\n";
echo "- 其他账号: " . count($otherAccounts) . " 个\n";

// 测试基础条件筛选
echo "\n=== 测试基础条件筛选 ===\n";
echo "----------------------------------------\n";

$baseAccountIds = $findAccountService->getBaseQualifiedAccountIds($plan, $giftCardAmount, $country);
echo "基础条件筛选结果: " . count($baseAccountIds) . " 个账号\n";

// 检查1650账号是否在基础筛选结果中
$found1650InBase = [];
foreach ($target1650Accounts as $account) {
    if (in_array($account->id, $baseAccountIds)) {
        $found1650InBase[] = $account;
    }
}

echo "1650账号在基础筛选中的数量: " . count($found1650InBase) . "\n";

// 测试约束条件筛选
echo "\n=== 测试约束条件筛选 ===\n";
echo "----------------------------------------\n";

$constraintAccountIds = $findAccountService->getConstraintQualifiedAccountIds($baseAccountIds, $plan, $giftCardAmount);
echo "约束条件筛选结果: " . count($constraintAccountIds) . " 个账号\n";

// 测试群聊绑定筛选
echo "\n=== 测试群聊绑定筛选 ===\n";
echo "----------------------------------------\n";

$giftCardInfo = [
    'amount' => $giftCardAmount,
    'country_code' => $country,
    'room_id' => '52742932719@chatroom'
];

$roomBindingAccountIds = $findAccountService->getRoomBindingQualifiedAccountIds($constraintAccountIds, $plan, $giftCardInfo);
echo "群聊绑定筛选结果: " . count($roomBindingAccountIds) . " 个账号\n";

// 测试容量检查筛选
echo "\n=== 测试容量检查筛选 ===\n";
echo "----------------------------------------\n";

$capacityAccountIds = $findAccountService->getCapacityQualifiedAccountIds($roomBindingAccountIds, $plan, $giftCardAmount);
echo "容量检查筛选结果: " . count($capacityAccountIds) . " 个账号\n";

// 测试1650优先筛选
echo "\n=== 测试1650优先筛选 ===\n";
echo "----------------------------------------\n";

$priority1650AccountIds = $findAccountService->get1650PriorityAccountIds($capacityAccountIds, $giftCardAmount);
echo "1650优先筛选结果: " . count($priority1650AccountIds) . " 个账号\n";

// 检查1650账号是否在最终结果中
$found1650InFinal = [];
foreach ($target1650Accounts as $account) {
    if (in_array($account->id, $priority1650AccountIds)) {
        $found1650InFinal[] = $account;
    }
}

echo "1650账号在最终结果中的数量: " . count($found1650InFinal) . "\n";

if (!empty($found1650InFinal)) {
    echo "\n找到的1650账号:\n";
    foreach ($found1650InFinal as $account) {
        $afterExchange = $account->amount + $giftCardAmount;
        echo "  {$account->account} - 余额:{$account->amount}, 计划ID:" . ($account->plan_id ?? 'NULL') . ", 兑换后:{$afterExchange}\n";
    }
}

// 测试完整流程
echo "\n=== 测试完整筛选流程 ===\n";
echo "----------------------------------------\n";

try {
    $optimalAccount = $findAccountService->findOptimalAccount($plan, $giftCardInfo['room_id'], $giftCardInfo, 1, true);
    
    if ($optimalAccount) {
        $afterExchange = $optimalAccount->amount + $giftCardAmount;
        echo "✅ 找到最优账号: {$optimalAccount->account}\n";
        echo "  余额: {$optimalAccount->amount}\n";
        echo "  计划ID: " . ($optimalAccount->plan_id ?? 'NULL') . "\n";
        echo "  兑换后: {$afterExchange}\n";
        
        if (abs($afterExchange - 1650) < 0.01) {
            echo "  🎯 这是目标1650账号！\n";
        } elseif (abs($afterExchange - 1700) < 0.01) {
            echo "  ⚠️  这是1700账号，可能有问题\n";
        }
    } else {
        echo "❌ 没有找到合适的账号\n";
    }
    
} catch (Exception $e) {
    echo "❌ 筛选过程出错: " . $e->getMessage() . "\n";
}

echo "\n=== 调试完成 ===\n"; 