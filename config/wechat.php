<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 微信机器人API配置
    |--------------------------------------------------------------------------
    |
    | 微信机器人API的相关配置信息
    |
    */

    'api_url' => env('WECHAT_API_URL', 'http://106.52.250.202:6666/'),

    'heartbeat_api_url' => env('WECHAT_HEARTBEAT_API_URL', 'http://43.140.224.234:6666/'),

    /*
    |--------------------------------------------------------------------------
    | 消息队列配置
    |--------------------------------------------------------------------------
    |
    | 微信消息队列的相关配置
    |
    */

    'queue' => [
        'enabled' => env('WECHAT_QUEUE_ENABLED', true),
        'name'    => env('WECHAT_QUEUE_NAME', 'wechat-message'),
        'timeout' => env('WECHAT_QUEUE_TIMEOUT', 30),
        'tries'   => env('WECHAT_QUEUE_TRIES', 3),
        'backoff' => [5, 10, 15], // 重试延迟时间（秒）
    ],

    /*
    |--------------------------------------------------------------------------
    | 监控配置
    |--------------------------------------------------------------------------
    |
    | 微信消息监控的相关配置
    |
    */

    'monitor' => [
        'enabled'          => env('WECHAT_MONITOR_ENABLED', true),
        'auto_refresh'     => env('WECHAT_MONITOR_AUTO_REFRESH', true),
        'refresh_interval' => env('WECHAT_MONITOR_REFRESH_INTERVAL', 5000), // 毫秒
        'page_size'        => env('WECHAT_MONITOR_PAGE_SIZE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | 默认房间配置
    |--------------------------------------------------------------------------
    |
    | 默认的微信群聊房间配置
    |
    */

    'default_rooms' => [
        'default'     => env('WECHAT_DEFAULT_ROOM', '45958721463@chatroom'),
        'gift_card'   => env('WECHAT_GIFT_CARD_ROOM', '45958721463@chatroom'),
        'verify_code' => env('WECHAT_VERIFY_CODE_ROOM', '20229649389@chatroom'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 消息模板配置
    |--------------------------------------------------------------------------
    |
    | 微信消息模板的相关配置
    |
    */

    'templates' => [
        'gift_card_success'     => "[强]礼品卡兑换成功\n---------------------------------\n账号：{account}\n国家：{country}   当前第{day}天\n礼品卡：{amount} {currency}\n兑换金额：{exchanged_amount}\n账户余款：{balance}\n计划总额：{total_amount}\n群聊绑定：{bind_room}\n时间：{time}",
        'redeem_plan_completed' => "[强]兑换目标达成通知\n---------------------------------\n账号：{account}\n国家：{country} 账户余款：{balance}",
        'redeem_account_ban'    => "❌ 账号禁用，请检测\n---------------------------------\n{account}",
        'verify_code_start'     => "查码请求已发，请等待...",
        'verify_code_success'   => "✅ 查码成功\n---------------------\n{account}\n{code}",
        'verify_code_failed'    => "❌ 查码失败\n---------------------\n{account}\n{code}",
    ],
];
