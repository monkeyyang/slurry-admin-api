<?php

require_once 'vendor/autoload.php';

use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccount;
use App\Services\Gift\FindAccountService;
use Illuminate\Support\Facades\DB;

// 初始化Laravel应用
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== FindAccountService 诊断测试 ===\n";

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

// 1. 基础条件筛选测试
echo "=== 第1层：基础条件筛选 ===\n";
$baseAccountIds = $findAccountService->getBaseQualifiedAccountIds($plan, $giftCardAmount, $country);
echo "基础条件筛选结果: " . count($baseAccountIds) . " 个账号\n";

if (empty($baseAccountIds)) {
    echo "❌ 基础条件筛选失败，没有符合条件的账号\n";
    exit(1);
}

// 显示前10个账号
$baseAccounts = ItunesTradeAccount::whereIn('id', array_slice($baseAccountIds, 0, 10))->get();
foreach ($baseAccounts as $account) {
    echo "- {$account->account} (ID:{$account->id}): 余额{$account->amount}, 兑换后" . ($account->amount + $giftCardAmount) . "\n";
}

// 2. 约束条件筛选测试
echo "\n=== 第2层：约束条件筛选 ===\n";
$constraintAccountIds = $findAccountService->getConstraintQualifiedAccountIds($baseAccountIds, $plan, $giftCardAmount);
echo "约束条件筛选结果: " . count($constraintAccountIds) . " 个账号\n";

if (empty($constraintAccountIds)) {
    echo "❌ 约束条件筛选失败\n";
    exit(1);
}

// 3. 房间绑定筛选测试
echo "\n=== 第3层：房间绑定筛选 ===\n";
$roomBindingAccountIds = $findAccountService->getRoomBindingQualifiedAccountIds($constraintAccountIds, $plan, $giftCardInfo);
echo "房间绑定筛选结果: " . count($roomBindingAccountIds) . " 个账号\n";

if (empty($roomBindingAccountIds)) {
    echo "❌ 房间绑定筛选失败\n";
    exit(1);
}

// 4. 容量检查筛选测试
echo "\n=== 第4层：容量检查筛选 ===\n";
$capacityAccountIds = $findAccountService->getCapacityQualifiedAccountIds($roomBindingAccountIds, $plan, $giftCardAmount);
echo "容量检查筛选结果: " . count($capacityAccountIds) . " 个账号\n";

if (empty($capacityAccountIds)) {
    echo "❌ 容量检查筛选失败\n";
    exit(1);
}

// 5. 每日计划筛选测试
echo "\n=== 第5层：每日计划筛选 ===\n";
$dailyPlanAccountIds = $findAccountService->getDailyPlanQualifiedAccountIds($capacityAccountIds, $plan, $giftCardAmount, $currentDay);
echo "每日计划筛选结果: " . count($dailyPlanAccountIds) . " 个账号\n";

if (empty($dailyPlanAccountIds)) {
    echo "❌ 每日计划筛选失败\n";
    exit(1);
}

// 6. 最终选择测试
echo "\n=== 第6层：最终选择 ===\n";
$optimalAccount = $findAccountService->findOptimalAccount($plan, $roomId, $giftCardInfo, $currentDay, true);

if ($optimalAccount) {
    echo "✅ 找到最优账号: {$optimalAccount->account} (ID:{$optimalAccount->id})\n";
    echo "- 余额: {$optimalAccount->amount}\n";
    echo "- 兑换后总额: " . ($optimalAccount->amount + $giftCardAmount) . "\n";
    echo "- 计划ID: {$optimalAccount->plan_id}\n";
    echo "- 房间ID: {$optimalAccount->room_id}\n";
} else {
    echo "❌ 最终选择失败\n";
}

echo "\n=== 诊断完成 ===\n";
