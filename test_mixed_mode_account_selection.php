<?php

require_once 'vendor/autoload.php';

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Services\Gift\FindAccountService;

// 初始化Laravel应用
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== 混充模式账号选择测试 ===\n";

// 获取计划信息
$plan = ItunesTradePlan::find(1);
if (!$plan) {
    echo "❌ 计划1不存在\n";
    exit;
}

echo "计划信息:\n";
echo "- 计划ID: {$plan->id}\n";
echo "- 总额度: {$plan->total_amount}\n";
echo "- 绑定房间: " . ($plan->bind_room ? '是' : '否') . "\n\n";

// 创建FindAccountService实例
$findAccountService = new FindAccountService();

// 测试参数
$giftCardInfo = [
    'amount'       => 150.00,
    'country_code' => 'CA',
    'room_id'      => '46321584173@chatroom'
];

echo "测试参数:\n";
echo "- 礼品卡金额: {$giftCardInfo['amount']}\n";
echo "- 国家: {$giftCardInfo['country_code']}\n";
echo "- 房间ID: {$giftCardInfo['room_id']}\n\n";

// 获取所有可用账号
$availableAccounts = ItunesTradeAccount::where('status', 'processing')
    ->where('login_status', 'valid')
    ->where('country_code', 'CA')
    ->whereNull('deleted_at')
    ->get();

echo "可用账号总数: {$availableAccounts->count()}\n\n";

// 模拟排序逻辑
echo "=== 排序逻辑测试 ===\n";

// 获取前20个账号进行测试
$testAccounts = $availableAccounts->take(20);

foreach ($testAccounts as $account) {
    $totalAfterExchange = $account->amount + $giftCardInfo['amount'];

    // 计算容量优先级
    $capacityPriority = 1;
    if (abs($totalAfterExchange - $plan->total_amount) < 0.01) {
        $capacityPriority = 3; // 正好充满
    } elseif ($totalAfterExchange < $plan->total_amount) {
        $capacityPriority = 2; // 可以预留
    }

    // 计算绑定优先级（混充模式）
    $bindingPriority = 3;
    if ($account->plan_id == $plan->id) {
        $bindingPriority = 1; // 绑定当前计划
    } elseif ($account->plan_id === null) {
        $bindingPriority = 2; // 无计划
    }

    echo "- {$account->account} (ID:{$account->id}): 余额{$account->amount}, 兑换后{$totalAfterExchange}, 绑定优先级{$bindingPriority}, 容量优先级{$capacityPriority}\n";
}

// 测试FindAccountService
echo "\n=== FindAccountService测试 ===\n";

try {
    $result = $findAccountService->findOptimalAccountForTest(
        $plan,
        $giftCardInfo['room_id'],
        $giftCardInfo
    );

    if ($result) {
        echo "✅ 找到候选账号:\n";
        foreach ($result as $index => $accountInfo) {
            echo "- 第" . ($index + 1) . "名: {$accountInfo['account']} (ID:{$accountInfo['id']}), 余额{$accountInfo['amount']}\n";
        }
    } else {
        echo "❌ 未找到候选账号\n";
    }
} catch (Exception $e) {
    echo "❌ 测试失败: " . $e->getMessage() . "\n";
}

// 检查目标账号是否在候选列表中
echo "\n=== 目标账号检查 ===\n";
$targetAccount      = 'ferrispatrick369612@gmail.com';
$targetAccountModel = ItunesTradeAccount::where('account', $targetAccount)->first();

if ($targetAccountModel) {
    $totalAfterExchange = $targetAccountModel->amount + $giftCardInfo['amount'];

    // 计算容量优先级
    $capacityPriority = 1;
    if (abs($totalAfterExchange - $plan->total_amount) < 0.01) {
        $capacityPriority = 3;
    } elseif ($totalAfterExchange < $plan->total_amount) {
        $capacityPriority = 2;
    }

    // 计算绑定优先级（混充模式）
    $bindingPriority = 3;
    if ($targetAccountModel->plan_id == $plan->id) {
        $bindingPriority = 1;
    } elseif ($targetAccountModel->plan_id === null) {
        $bindingPriority = 2;
    }

    echo "目标账号 {$targetAccount}:\n";
    echo "- 余额: {$targetAccountModel->amount}\n";
    echo "- 兑换后总额: {$totalAfterExchange}\n";
    echo "- 绑定优先级: {$bindingPriority}\n";
    echo "- 容量优先级: {$capacityPriority}\n";
    echo "- 计划ID: {$targetAccountModel->plan_id}\n";
    echo "- 房间ID: {$targetAccountModel->room_id}\n";

    if ($bindingPriority == 1 && $capacityPriority == 3) {
        echo "✅ 目标账号应该被优先选择\n";
    } else {
        echo "❌ 目标账号优先级不够高\n";
    }
} else {
    echo "❌ 目标账号不存在\n";
}

echo "\n=== 测试完成 ===\n";
