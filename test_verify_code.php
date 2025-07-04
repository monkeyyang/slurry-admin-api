<?php

require_once 'vendor/autoload.php';

use App\Jobs\ProcessVerifyCodeJob;
use App\Models\ItunesAccountVerify;
use App\Services\EncryptionService;

// 测试查码功能
echo "=== 查码功能测试 ===\n";

// 1. 测试数据准备
$testAccounts = [
    'test1@example.com',
    'test2@example.com',
    'test3@example.com'
];

$roomId = 'test_room_123';
$msgId = 'test_msg_456';
$wxid = 'test_wx_789';

echo "测试参数:\n";
echo "群聊ID: $roomId\n";
echo "消息ID: $msgId\n";
echo "微信ID: $wxid\n";
echo "账号数量: " . count($testAccounts) . "\n";
echo "账号列表: " . implode(', ', $testAccounts) . "\n\n";

// 2. 检查账号是否存在
echo "检查账号是否存在:\n";
foreach ($testAccounts as $account) {
    $exists = ItunesAccountVerify::where('account', $account)->exists();
    echo "账号 $account: " . ($exists ? "✓ 存在" : "✗ 不存在") . "\n";
}
echo "\n";

// 3. 模拟查码请求
echo "模拟查码请求:\n";
try {
    // 创建Job实例
    $job = new ProcessVerifyCodeJob($roomId, $msgId, $wxid, $testAccounts, 1);
    
    echo "Job创建成功\n";
    echo "Job参数:\n";
    echo "- roomId: " . $job->roomId . "\n";
    echo "- msgId: " . $job->msgId . "\n";
    echo "- wxid: " . $job->wxid . "\n";
    echo "- accounts: " . implode(', ', $job->accounts) . "\n";
    echo "- uid: " . $job->uid . "\n\n";
    
    // 注意：这里只是测试Job创建，实际执行需要队列环境
    echo "注意：实际查码需要队列环境，这里只测试Job创建\n";
    
} catch (Exception $e) {
    echo "Job创建失败: " . $e->getMessage() . "\n";
}

// 4. 测试代理服务
echo "\n=== 代理服务测试 ===\n";
try {
    $proxy = \App\Services\ProxyService::getProxy();
    echo "当前代理: " . ($proxy ?: '无') . "\n";
    
    $proxyList = \App\Services\ProxyService::getProxyList();
    echo "代理列表: " . (empty($proxyList) ? '无' : implode(', ', $proxyList)) . "\n";
    
} catch (Exception $e) {
    echo "代理服务测试失败: " . $e->getMessage() . "\n";
}

// 5. 测试配置
echo "\n=== 配置测试 ===\n";
echo "查码超时时间: " . config('proxy.verify_timeout', 60) . "秒\n";
echo "查码间隔时间: " . config('proxy.verify_interval', 5) . "秒\n";
echo "请求超时时间: " . config('proxy.request_timeout', 10) . "秒\n";

echo "\n=== 测试完成 ===\n";
echo "要实际测试查码功能，请确保：\n";
echo "1. 队列服务已启动 (php artisan queue:work)\n";
echo "2. 数据库中有对应的账号记录\n";
echo "3. 账号的verify_url字段已正确设置\n";
echo "4. 代理IP已正确配置（如需要）\n"; 