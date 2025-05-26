<?php

// 临时测试文件
function parseAccountAndPassword(string $accountString): array
{
    // 账号和密码可能以空格、制表符或其他分隔符连接
    // 例如: "gordony1982@icloud.com\tzIxHkNvAV0" 或 "gordony1982@icloud.com zIxHkNvAV0"
    
    // 尝试不同的分隔符
    $separators = ['\t', ' ', '|', ','];
    
    foreach ($separators as $separator) {
        if ($separator === '\t') {
            // 处理制表符
            $parts = explode("\t", $accountString);
        } else {
            $parts = explode($separator, $accountString);
        }
        
        if (count($parts) >= 2) {
            return [
                'account' => trim($parts[0]),
                'password' => trim($parts[1])
            ];
        }
    }
    
    // 如果没有找到分隔符，返回原始字符串作为账号，密码为空
    return [
        'account' => trim($accountString),
        'password' => ''
    ];
}

// 测试用例
$testCases = [
    'gordony1982@icloud.com\tzIxHkNvAV0',  // 制表符分隔
    'gordony1982@icloud.com zIxHkNvAV0',   // 空格分隔
    'gordony1982@icloud.com|zIxHkNvAV0',   // 管道符分隔
    'gordony1982@icloud.com,zIxHkNvAV0',   // 逗号分隔
    'gordony1982@icloud.com',              // 只有账号
];

foreach ($testCases as $testCase) {
    echo "Input: " . json_encode($testCase) . "\n";
    $result = parseAccountAndPassword($testCase);
    echo "Output: " . json_encode($result) . "\n";
    echo "---\n";
} 