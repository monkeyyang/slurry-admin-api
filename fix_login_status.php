<?php

require_once 'vendor/autoload.php';

// 加载Laravel应用
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ItunesTradeAccount;

echo "开始统一登录状态值...\n";

// 将所有 failed 和 logout 状态统一为 invalid
$updatedCount = ItunesTradeAccount::whereIn('login_status', ['failed', 'logout'])
    ->update(['login_status' => 'invalid']);

echo "已更新 {$updatedCount} 个账号的登录状态\n";

// 显示当前登录状态分布
$statusCounts = ItunesTradeAccount::selectRaw('login_status, count(*) as count')
    ->whereNotNull('login_status')
    ->groupBy('login_status')
    ->get();

echo "\n当前登录状态分布:\n";
foreach ($statusCounts as $status) {
    echo "- {$status->login_status}: {$status->count} 个账号\n";
}

echo "\n修复完成！\n"; 