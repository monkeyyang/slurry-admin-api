<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TradeMonitorService;
use Illuminate\Http\Request;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\WebSocket\WsServer;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use SplObjectStorage;
use React\Socket\Server as SocketServer;
use React\EventLoop\Loop;

class TradeMonitorWebSocketController extends Controller implements MessageComponentInterface
{
    protected SplObjectStorage $clients;
    protected TradeMonitorService $monitorService;

    public function __construct()
    {
        $this->clients = new SplObjectStorage;
        $this->monitorService = new TradeMonitorService();
    }

    public function onOpen(ConnectionInterface $conn)
    {
        // 处理CORS
        $this->handleCors($conn);
        
        // 验证token
        $token = $this->extractTokenFromQuery($conn);
        if (!$this->validateToken($token)) {
            $conn->close();
            return;
        }

        $this->clients->attach($conn);
        echo "新的连接! ({$conn->resourceId}) 来自: " . $this->getClientOrigin($conn) . "\n";

        // 发送初始状态
        $this->sendRealtimeStatus($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        
        if (!$data || !isset($data['type'])) {
            return;
        }

        switch ($data['type']) {
            case 'ping':
                $from->send(json_encode(['type' => 'pong']));
                break;
            case 'getStatus':
                $this->sendRealtimeStatus($from);
                break;
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        echo "连接 {$conn->resourceId} 已断开\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "发生错误: {$e->getMessage()}\n";
        $conn->close();
    }

    /**
     * 广播日志消息
     */
    public function broadcastLog(array $logEntry): void
    {
        $message = json_encode([
            'type' => 'log',
            'data' => $logEntry
        ]);

        foreach ($this->clients as $client) {
            $client->send($message);
        }
    }

    /**
     * 广播状态更新
     */
    public function broadcastStatus(): void
    {
        try {
            $status = $this->monitorService->getRealtimeStatus();
            $message = json_encode([
                'type' => 'status',
                'data' => $status
            ]);

            foreach ($this->clients as $client) {
                $client->send($message);
            }
        } catch (\Exception $e) {
            echo "广播状态失败: {$e->getMessage()}\n";
        }
    }

    /**
     * 发送实时状态给单个连接
     */
    private function sendRealtimeStatus(ConnectionInterface $conn): void
    {
        try {
            $status = $this->monitorService->getRealtimeStatus();
            $message = json_encode([
                'type' => 'status',
                'data' => $status
            ]);
            $conn->send($message);
        } catch (\Exception $e) {
            echo "发送状态失败: {$e->getMessage()}\n";
        }
    }

    /**
     * 从查询参数中提取token
     */
    private function extractTokenFromQuery(ConnectionInterface $conn): ?string
    {
        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $params);
        return $params['token'] ?? null;
    }

    /**
     * 验证token
     */
    private function validateToken(?string $token): bool
    {
        // 临时允许空token或简单token，用于开发和测试
        if (!$token || $token === 'null' || $token === 'undefined') {
            echo "警告: 使用空token连接，仅用于开发环境\n";
            return true; // 开发环境允许
        }

        // 这里应该实现实际的token验证逻辑
        // 例如验证JWT token或检查数据库中的session
        try {
            // 简单的token验证示例
            // 在实际应用中，应该使用更安全的验证方法
            return !empty($token) && strlen($token) > 5;
        } catch (\Exception $e) {
            echo "Token验证错误: {$e->getMessage()}\n";
            return false;
        }
    }

    /**
     * 启动WebSocket服务器
     */
    public static function startServer(int $port = 8080): void
    {
        $loop = Loop::get();
        $webSocketController = new static();

        // 启动Redis订阅
        $webSocketController->startRedisSubscription($loop);

        $wsServer = new WsServer($webSocketController);
        
        // 设置允许的来源（CORS）
        $wsServer->setStrictSubProtocolCheck(false);
        
        $server = IoServer::factory(
            new HttpServer($wsServer),
            $port,
            $loop
        );

        echo "WebSocket服务器启动在端口 {$port}\n";
        echo "允许的前端域名: https://1105.me\n";
        echo "WebSocket连接地址: wss://slurry-api.1105.me:$port/ws/monitor\n";
        $server->run();
    }

    /**
     * 启动Redis订阅
     */
    private function startRedisSubscription($loop): void
    {
        // 这里可以使用ReactPHP的Redis客户端来订阅Redis频道
        // 由于简化实现，我们使用定时器来检查Redis消息
        $loop->addPeriodicTimer(1.0, function () {
            $this->checkRedisMessages();
        });
    }

    /**
     * 处理CORS
     */
    private function handleCors(ConnectionInterface $conn): void
    {
        $origin = $this->getClientOrigin($conn);
        $allowedOrigins = [
            'https://1105.me',
            'https://www.1105.me',
            'http://localhost:3000',  // 开发环境
            'http://localhost:8080',  // 开发环境
        ];

        if (!in_array($origin, $allowedOrigins)) {
            echo "拒绝来自未授权域名的连接: $origin\n";
            // 在生产环境中可以选择关闭连接
            // $conn->close();
        }
    }

    /**
     * 获取客户端来源
     */
    private function getClientOrigin(ConnectionInterface $conn): string
    {
        $headers = $conn->httpRequest->getHeaders();
        return $headers['Origin'][0] ?? $headers['origin'][0] ?? 'unknown';
    }

    /**
     * 检查Redis消息
     */
    private function checkRedisMessages(): void
    {
        try {
            // 简化的Redis消息检查
            // 在实际应用中，应该使用proper的Redis pub/sub
            $messages = \Illuminate\Support\Facades\Redis::lrange('websocket-messages', 0, -1);
            if (!empty($messages)) {
                foreach ($messages as $message) {
                    $this->broadcastMessage($message);
                }
                \Illuminate\Support\Facades\Redis::del('websocket-messages');
            }
        } catch (\Exception $e) {
            echo "检查Redis消息失败: {$e->getMessage()}\n";
        }
    }

    /**
     * 广播消息给所有客户端
     */
    private function broadcastMessage(string $message): void
    {
        foreach ($this->clients as $client) {
            $client->send($message);
        }
    }
} 