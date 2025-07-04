<?php

require_once 'vendor/autoload.php';

// 启动Laravel应用
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== 查码响应修复验证 ===\n";

// 模拟你提供的响应数据
$responseData = [
    'code' => 1,
    'msg' => 'ok',
    'data' => [
        'code' => 'Apple 账户代码为：577730。请勿与他人共享。\n\n@apple.com #577730 %apple.com',
        'code_time' => '2025-07-05 02:51:31',
        'expired_date' => '2025-09-20 00:00:00'
    ]
];

$account = 'noahfc3richardsonipg@gmail.com';

echo "测试响应数据:\n";
echo json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

// 模拟处理逻辑（与ProcessVerifyCodeJob中的逻辑一致）
if (isset($responseData['code'])) {
    if ($responseData['code'] === 1) {
        echo "✅ 响应码正确: code = 1 (成功)\n";
        
        $verifyCode = $responseData['data']['code'] ?? '';
        if (!empty($verifyCode)) {
            echo "✅ 验证码不为空\n";
            
            // 提取纯数字验证码
            $patterns = [
                '/#(\d+)/',           // #577730
                '/代码为：(\d+)/',     // 代码为：577730
                '/验证码[：:]\s*(\d+)/', // 验证码：577730
                '/(\d{6})/',          // 6位数字
                '/(\d{4,8})/',        // 4-8位数字
            ];
            
            $pureCode = $verifyCode;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $verifyCode, $matches)) {
                    $pureCode = $matches[1];
                    echo "✅ 验证码提取成功: {$pureCode}\n";
                    break;
                }
            }
            
            echo "✅ 应该发送微信消息: {$account}:{$pureCode}\n";
            echo "✅ 不应该出现'查码响应格式错误'的日志\n";
            
        } else {
            echo "❌ 验证码为空\n";
        }
        
    } elseif ($responseData['code'] === 0) {
        echo "❌ 响应码表示失败: code = 0\n";
        $errorMsg = $responseData['msg'] ?? '查码失败';
        echo "错误信息: {$errorMsg}\n";
        
    } else {
        echo "❌ 未知响应码: code = {$responseData['code']}\n";
        echo "❌ 这里会记录'查码响应格式错误'\n";
    }
    
} else {
    echo "❌ 响应格式错误，缺少code字段\n";
    echo "❌ 这里会记录'查码响应格式错误'\n";
}

echo "\n=== 验证完成 ===\n";
echo "如果上面的结果显示正确，但实际运行仍然有问题，\n";
echo "请确保：\n";
echo "1. 已清除所有Laravel缓存\n";
echo "2. 已重启队列进程\n";
echo "3. 队列进程使用的是最新代码\n"; 