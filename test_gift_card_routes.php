<?php

/**
 * 礼品卡批量兑换API测试脚本
 * 用于测试新的礼品卡批量兑换路由和功能
 */

// 测试配置
$baseUrl = 'http://localhost:8000/api'; // 根据实际情况修改
$token = 'your-auth-token'; // 需要替换为实际的认证token

// 测试数据
$testData = [
    'room_id' => 'test@chatroom.com',
    'codes' => [
        'TESTCODE001',
        'TESTCODE002',
        'TESTCODE003'
    ],
    'card_type' => 'fast',
    'card_form' => 'image'
];

echo "=== 礼品卡批量兑换API测试 ===\n\n";

// 1. 测试批量兑换接口
echo "1. 测试批量兑换接口\n";
echo "POST {$baseUrl}/giftcards/bulk-redeem\n";
echo "数据: " . json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $baseUrl . '/giftcards/bulk-redeem',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($testData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "响应状态码: {$httpCode}\n";
echo "响应内容: {$response}\n\n";

if ($httpCode === 200) {
    $responseData = json_decode($response, true);
    if (isset($responseData['data']['batch_id'])) {
        $batchId = $responseData['data']['batch_id'];
        
        // 2. 测试查询进度接口
        echo "2. 测试查询进度接口\n";
        echo "GET {$baseUrl}/giftcards/batch/{$batchId}/progress\n";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . "/giftcards/batch/{$batchId}/progress",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ]
        ]);
        
        $progressResponse = curl_exec($ch);
        $progressHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "响应状态码: {$progressHttpCode}\n";
        echo "响应内容: {$progressResponse}\n\n";
        
        // 3. 测试取消任务接口
        echo "3. 测试取消任务接口\n";
        echo "POST {$baseUrl}/giftcards/batch/{$batchId}/cancel\n";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . "/giftcards/batch/{$batchId}/cancel",
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ]
        ]);
        
        $cancelResponse = curl_exec($ch);
        $cancelHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "响应状态码: {$cancelHttpCode}\n";
        echo "响应内容: {$cancelResponse}\n\n";
    }
}

echo "=== 测试完成 ===\n";

// 显示路由信息
echo "\n=== 路由信息 ===\n";
echo "批量兑换: POST /api/giftcards/bulk-redeem\n";
echo "查询进度: GET /api/giftcards/batch/{batchId}/progress\n";
echo "取消任务: POST /api/giftcards/batch/{batchId}/cancel\n";

echo "\n=== 请求参数说明 ===\n";
echo "批量兑换参数:\n";
echo "- room_id: 群聊ID (必填)\n";
echo "- codes: 礼品卡码数组 (必填，1-100个)\n";
echo "- card_type: 卡类型 (必填，fast/slow)\n";
echo "- card_form: 卡形式 (必填，image/code)\n";

echo "\n=== 响应格式说明 ===\n";
echo "成功响应:\n";
echo "{\n";
echo "  \"code\": 0,\n";
echo "  \"message\": \"批量兑换任务已开始处理\",\n";
echo "  \"data\": {\n";
echo "    \"batch_id\": \"uuid\",\n";
echo "    \"total_cards\": 3,\n";
echo "    \"progress_url\": \"http://domain/api/giftcards/batch/{batchId}/progress\"\n";
echo "  }\n";
echo "}\n"; 