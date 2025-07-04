<?php

require_once 'vendor/autoload.php';

use App\Services\ProxyService;

echo "=== 代理IP测试 ===\n";

// 代理列表
$proxyList = [
    'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
    'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
    'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
    'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
    'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
    'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
    'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
    'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
    'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
    'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
];

echo "开始测试 " . count($proxyList) . " 个代理IP...\n\n";

$availableProxies = [];
$failedProxies = [];

foreach ($proxyList as $index => $proxy) {
    echo "测试代理 " . ($index + 1) . ": " . $proxy . "\n";
    
    $startTime = microtime(true);
    $isAvailable = ProxyService::testProxy($proxy);
    $endTime = microtime(true);
    $responseTime = round(($endTime - $startTime) * 1000, 2);
    
    if ($isAvailable) {
        echo "✓ 可用 (响应时间: {$responseTime}ms)\n";
        $availableProxies[] = $proxy;
    } else {
        echo "✗ 不可用 (响应时间: {$responseTime}ms)\n";
        $failedProxies[] = $proxy;
    }
    
    echo "\n";
    
    // 避免请求过于频繁
    if ($index < count($proxyList) - 1) {
        sleep(1);
    }
}

echo "=== 测试结果汇总 ===\n";
echo "总代理数: " . count($proxyList) . "\n";
echo "可用代理: " . count($availableProxies) . "\n";
echo "不可用代理: " . count($failedProxies) . "\n";
echo "可用率: " . round((count($availableProxies) / count($proxyList)) * 100, 2) . "%\n\n";

if (!empty($availableProxies)) {
    echo "可用代理列表:\n";
    foreach ($availableProxies as $proxy) {
        echo "- " . $proxy . "\n";
    }
    echo "\n";
}

if (!empty($failedProxies)) {
    echo "不可用代理列表:\n";
    foreach ($failedProxies as $proxy) {
        echo "- " . $proxy . "\n";
    }
    echo "\n";
}

// 测试代理轮询功能
echo "=== 代理轮询测试 ===\n";
for ($i = 0; $i < 5; $i++) {
    $proxy = ProxyService::getProxy();
    echo "轮询 " . ($i + 1) . ": " . ($proxy ?: '无可用代理') . "\n";
}

echo "\n=== 测试完成 ===\n";
echo "建议:\n";
echo "1. 如果可用率较低，请检查代理IP的有效性\n";
echo "2. 确保网络连接正常\n";
echo "3. 可以调整测试超时时间\n";
echo "4. 建议定期测试代理可用性\n"; 