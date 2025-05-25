<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 礼品卡兑换配置
    |--------------------------------------------------------------------------
    */

    // 队列配置
    'queue' => [
        'connection' => env('GIFT_CARD_QUEUE_CONNECTION', 'redis'),
        'queue_name' => env('GIFT_CARD_QUEUE_NAME', 'gift_card_exchange'),
    ],

    // 轮询配置
    'polling' => [
        'max_attempts' => env('GIFT_CARD_POLLING_MAX_ATTEMPTS', 20),
        'interval' => env('GIFT_CARD_POLLING_INTERVAL', 3), // 秒
    ],

    // 兑换配置
    'redemption' => [
        'interval' => env('GIFT_CARD_REDEMPTION_INTERVAL', 6), // 同一账户兑换多张卡时的时间间隔
    ],

    // API配置
    'api' => [
        'base_url' => env('GIFT_CARD_API_BASE_URL', 'https://api.example.com'),
        'timeout' => env('GIFT_CARD_API_TIMEOUT', 30),
    ],
]; 