<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Account Monitor Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the account monitoring service
    |
    */

    'client_id' => env('ACCOUNT_MONITOR_CLIENT_ID', null),

    'websocket' => [
        'url' => env('ACCOUNT_MONITOR_WS_URL', 'ws://47.76.200.188:8080'),
        'ping_interval' => env('ACCOUNT_MONITOR_PING_INTERVAL', 30),
        'reconnect_delay' => env('ACCOUNT_MONITOR_RECONNECT_DELAY', 5),
    ],
];
