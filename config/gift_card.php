<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 礼品卡API配置
    |--------------------------------------------------------------------------
    |
    | 礼品卡服务相关配置
    |
    */
    
    // API基础URL
    'api_base_url' => env('GIFT_CARD_API_URL', 'http://47.76.200.188:8080/api'),
    
    // 任务轮询配置
    'polling' => [
        'max_attempts' => env('GIFT_CARD_POLL_MAX_ATTEMPTS', 20),   // 最大轮询次数
        'interval' => env('GIFT_CARD_POLL_INTERVAL', 3),            // 轮询间隔(秒)
    ],
    
    // 兑换配置
    'redemption' => [
        'interval' => env('GIFT_CARD_REDEMPTION_INTERVAL', 6),      // 同一账户兑换多张卡时的时间间隔(秒)
    ],
    
    // WebSocket配置
    'websocket' => [
        'client_id' => env('GIFT_CARD_WS_CLIENT_ID', null),         // WebSocket客户端ID
        'enabled' => env('GIFT_CARD_WS_ENABLED', false),            // 是否启用WebSocket
    ],
]; 