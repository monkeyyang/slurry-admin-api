<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * 添加或者删除函数后执行composer dump-autoload
 * */

function getAppConfig(string $key) {
    return Config::get("self.$key");
}

function buildTree($collection, $parentId = null)
{
    $tree = [];

    foreach ($collection as $item) {
        if ($item->parent_id === $parentId) {
            $children = buildTree($collection, $item->id);
            if ($children) {
                $item->children = $children;
            }
            $tree[] = $item;
        }
    }

    return $tree;
}

/**
 * 检测机器人心跳
 *
 * @return array 返回检测结果，包含状态和响应内容
 */
function check_bot_heartbeat(): array
{
    try {
        $response = Http::withHeaders([
            'User-Agent' => 'Apifox/1.0.0 (https://www.apifox.cn)',
            'Content-Type' => 'application/json',
        ])->post('http://43.140.224.234:6666/', [
            'type' => 'PING'
        ]);

        // 获取完整响应信息
        $statusCode = $response->status();
        $body = $response->body();
        $json = $response->json();

        // 记录响应信息到日志
        Log::info('机器人心跳检测响应', [
            'status_code' => $statusCode,
            'response' => $body,
            'parsed_json' => $json
        ]);

        // 打印响应内容
        // echo "机器人心跳检测响应状态码: " . $statusCode . PHP_EOL;
        // echo "响应内容: " . $body . PHP_EOL;

        // 根据响应判断机器人是否在线
        $isOnline = $statusCode === 200;
        if ($isOnline && isset($json['status']) && $json['status'] === 'ok') {
            $isOnline = true;
        }

        return [
            'success' => $isOnline,
            'status_code' => $statusCode,
            'response' => $body,
            'data' => $json
        ];
    } catch (\Exception $e) {
        // 处理异常
        Log::error('机器人心跳检测失败', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        echo "机器人心跳检测失败: " . $e->getMessage() . PHP_EOL;

        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * 向微信群聊发送指定类型和指定内容的消息
 *
 * @param string $roomId
 * @param string $msg
 * @param string $type
 * @return bool
 */
function send_msg_to_wechat(string $roomId, string $msg, string $type = 'MT_SEND_TEXTMSG'): bool
{
    // 拼接content
    $content = [
        'data' => [
            'to_wxid' => $roomId,
            'content' => $msg
        ],
        'client_id' => 1,
        'type'      => $type
    ];
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => 'http://106.52.250.202:6666/',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($content),
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Apifox/1.0.0 (https://www.apifox.cn)',
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($curl);
    curl_close($curl);

    return true;
}

/**
 * 发送登录请求
 *
 * @param array $accounts 账号列表
 * @return void
 */
function send_async_login_request(array $accounts): void
{
    $loginUrl = 'http://47.76.200.188:8080/api/login_poll/new';
    try {

        $loginData = [
            'list' => []
        ];

        $id = 1;
        foreach ($accounts as $account) {
            $loginData['list'][] = [
                'id' => $id++,
                'username' => $account['account'],
                'password' => $account['password'],
                'VerifyUrl' => $account['api_url'] ?? ''
            ];
        }

        $response = Http::timeout(30)->post($loginUrl, $loginData);
        $responseData = $response->json(); // 获取JSON响应数据
        $statusCode = $response->status(); // 获取HTTP状态码
        Log::info('登录请求发送成功且收到回调', [
            'url' => $loginUrl,
            'accounts_count' => count($loginData['list']),
            'request_data' => $loginData,
            'response_status' => $statusCode,
            'response_data' => $responseData,
            'success' => $response->successful() // 是否为成功响应(2xx)
        ]);

    } catch (\Exception $e) {
        Log::error('登录请求发送失败: ' . $e->getMessage(), [
            'url' => $loginUrl,
            'accounts' => $accounts
        ]);
    }
}
