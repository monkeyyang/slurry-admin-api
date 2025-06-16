<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Http\Controllers\Api\TradeMonitorWebSocketController;

// 启动WebSocket服务器
$port = $argv[1] ?? 8080;
echo "正在启动交易监控WebSocket服务器...\n";
TradeMonitorWebSocketController::startServer((int)$port); 