<?php

require_once __DIR__ . '/vendor/autoload.php';

// 测试监控API接口

$baseUrl = 'http://localhost:8000/api'; // 根据实际情况调整
$token = 'your-auth-token'; // 根据实际情况调整

function makeRequest($url, $method = 'GET', $data = null, $token = null) {
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'body' => json_decode($response, true)
    ];
}

echo "=== 交易监控API测试 ===\n\n";

// 1. 测试获取统计数据
echo "1. 测试获取统计数据\n";
$response = makeRequest($baseUrl . '/trade/monitor/stats', 'GET', null, $token);
echo "状态码: " . $response['code'] . "\n";
echo "响应: " . json_encode($response['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// 2. 测试获取实时状态
echo "2. 测试获取实时状态\n";
$response = makeRequest($baseUrl . '/trade/monitor/status', 'GET', null, $token);
echo "状态码: " . $response['code'] . "\n";
echo "响应: " . json_encode($response['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// 3. 测试获取日志列表
echo "3. 测试获取日志列表\n";
$params = [
    'pageNum' => 1,
    'pageSize' => 10,
    'level' => 'INFO'
];
$queryString = http_build_query($params);
$response = makeRequest($baseUrl . '/trade/monitor/logs?' . $queryString, 'GET', null, $token);
echo "状态码: " . $response['code'] . "\n";
echo "响应: " . json_encode($response['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// 4. 测试搜索日志
echo "4. 测试搜索日志\n";
$params = [
    'pageNum' => 1,
    'pageSize' => 5,
    'keyword' => '礼品卡',
    'status' => 'success'
];
$queryString = http_build_query($params);
$response = makeRequest($baseUrl . '/trade/monitor/logs?' . $queryString, 'GET', null, $token);
echo "状态码: " . $response['code'] . "\n";
echo "响应: " . json_encode($response['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== 测试完成 ===\n";
echo "注意: 如果返回401错误，请检查认证token\n";
echo "如果返回404错误，请检查路由配置\n";
echo "如果返回500错误，请检查数据库连接和模型关系\n"; 