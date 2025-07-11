<?php

namespace App\Services\Gift;


use App\Models\ItunesTradeAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;

class AccountMonitorService
{
    protected     $token;
    protected     $connection;
    protected     $loop;
    protected int $pingInterval;

    public function __construct()
    {
        $this->token        = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJkZXZpY2VfaWQiOiI5YmI5N2E3MC01OTM4LTExZjAtOTRiNy02YmMyOTMzOTdkMTQiLCJleHAiOjE3ODMyMTQ4NDcsImlhdCI6MTc1MTY3ODg0NywidXNlcl9pZCI6Mn0.kyjzHW-JuZWOWcTxkhfqBlPTn_XPu8PYdcxJlL464sU';
        $this->pingInterval = config('account_monitor.websocket.ping_interval', 30);
        $this->loop         = Loop::get();
    }

    /**
     * 获取WebSocket专用日志实例
     */
    protected function getLogger(): LoggerInterface
    {
        return Log::channel('websocket_monitor');
    }

    public function startMonitoring(): void
    {
        $websocketUrl = config('account_monitor.websocket.url') . '/api/ws?token=' . $this->token;

        $this->getLogger()->info("开始建立WebSocket连接", [
            'token'         => $this->token,
            'websocket_url' => $websocketUrl,
            'ping_interval' => $this->pingInterval,
            'timestamp'     => now()->toISOString()
        ]);

        $connector = new Connector($this->loop);

        $connector($websocketUrl)
            ->then(function (WebSocket $conn) {
                $this->connection = $conn;

                $this->getLogger()->info("WebSocket连接成功", [
                    'token'          => $this->token,
                    'local_address'  => method_exists($conn, 'getLocalAddress') ? $conn->getLocalAddress() : 'unknown',
                    'remote_address' => method_exists($conn, 'getRemoteAddress') ? $conn->getRemoteAddress() : 'unknown',
                    'timestamp'      => now()->toISOString()
                ]);

                // 设置定时ping
                $this->loop->addPeriodicTimer($this->pingInterval, function () use ($conn) {
                    try {
                        $pingData = json_encode(['type' => 'ping']);
                        $conn->send($pingData);
                        $this->getLogger()->debug("发送ping消息", [
                            'ping_data' => $pingData,
                            'timestamp' => now()->toISOString()
                        ]);
                    } catch (\Exception $e) {
                        $this->getLogger()->error("发送ping失败", [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                });

                // 消息处理
                $conn->on('message', function ($msg) {
                    $this->handleMessage($msg);
                });

                // 关闭处理
                $conn->on('close', function ($code = null, $reason = null) {
                    $this->getLogger()->error("WebSocket连接关闭", [
                        'close_code'   => $code,
                        'close_reason' => $reason,
                        'timestamp'    => now()->toISOString()
                    ]);
                    $this->reconnect();
                });

                // 错误处理
                $conn->on('error', function ($error) {
                    $this->getLogger()->error("WebSocket连接错误", [
                        'error'     => $error instanceof \Exception ? $error->getMessage() : (string)$error,
                        'timestamp' => now()->toISOString()
                    ]);
                });

            }, function (\Exception $e) {
                $this->getLogger()->error("WebSocket连接失败", [
                    'error'         => $e->getMessage(),
                    'trace'         => $e->getTraceAsString(),
                    'websocket_url' => config('account_monitor.websocket.url') . '/api/ws?token=' . $this->token,
                    'timestamp'     => now()->toISOString()
                ]);
                $this->reconnect();
            });
    }

    protected function handleMessage($msg): void
    {
        // 获取消息内容（处理Ratchet消息对象）
        $messageContent = $msg instanceof MessageInterface ? $msg->getPayload() : (string)$msg;

        // 记录收到的原始消息
        $this->getLogger()->info("收到WebSocket消息", [
            'raw_message'    => $messageContent,
            'message_length' => strlen($messageContent),
            'timestamp'      => now()->toISOString()
        ]);

        try {
            $data = json_decode($messageContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->getLogger()->error("JSON解析失败", [
                    'error'       => json_last_error_msg(),
                    'raw_message' => $messageContent
                ]);
                return;
            }

            // 记录解析后的数据结构
            $this->getLogger()->debug("解析后的消息数据", [
                'parsed_data' => $data,
                'data_type'   => $data['type'] ?? 'unknown'
            ]);

            if ($data['type'] === 'ping') {
                $this->connection->send(json_encode(['type' => 'pong']));
                $this->getLogger()->debug("收到ping，回复pong");
                return;
            }

            // 处理业务消息
            switch ($data['type']) {
                case 'init':
                    $this->getLogger()->info("处理初始化数据", [
                        'data_count' => count($data['value'] ?? [])
                    ]);
                    $this->processInitData($data);
                    break;
                case 'update':
                    $this->getLogger()->info("处理更新数据", [
                        'data_count' => count($data['value'] ?? [])
                    ]);
                    $this->processUpdateData($data);
                    break;
                case 'delete':
                    $this->getLogger()->info("处理删除数据", [
                        'data_count' => count($data['value'] ?? [])
                    ]);
                    $this->processDeleteData($data);
                    break;
                default:
                    $this->getLogger()->warning("未知的消息类型", [
                        'message_type' => $data['type'],
                        'full_data'    => $data
                    ]);
            }

        } catch (\Exception $e) {
            $this->getLogger()->error("处理WebSocket消息异常", [
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
                'raw_message' => $messageContent
            ]);
        }
    }

    protected function processInitData(array $data): void
    {
        $accounts = $data['value'] ?? [];

        $this->getLogger()->info("开始处理初始化数据", [
            'total_accounts' => is_array($accounts) ? count($accounts) : 0,
            'full_data'      => $data
        ]);

        // 检查数据是否为空或null
        if (empty($accounts) || !is_array($accounts)) {
            $this->getLogger()->info("初始化数据为空，跳过处理", [
                'value_type'    => gettype($accounts),
                'value_content' => $accounts
            ]);
            return;
        }

        // 批量初始化账号状态
        foreach ($accounts as $index => $accountInfo) {
            $this->getLogger()->debug("处理初始化账号", [
                'index'        => $index,
                'account_info' => $accountInfo
            ]);
            $this->updateAccountStatus($accountInfo);
        }

        $this->getLogger()->info("初始化数据处理完成", [
            'processed_count' => count($accounts)
        ]);
    }

    protected function processUpdateData(array $data): void
    {
        $accounts = $data['value'] ?? [];

        $this->getLogger()->info("开始处理更新数据", [
            'total_accounts' => is_array($accounts) ? count($accounts) : 0,
            'full_data'      => $data
        ]);

        // 检查数据是否为空或null
        if (empty($accounts) || !is_array($accounts)) {
            $this->getLogger()->info("更新数据为空，跳过处理", [
                'value_type'    => gettype($accounts),
                'value_content' => $accounts
            ]);
            return;
        }

        // 更新单个账号状态
        foreach ($accounts as $index => $accountInfo) {
            $this->getLogger()->debug("处理更新账号", [
                'index'        => $index,
                'account_info' => $accountInfo
            ]);
            $this->updateAccountStatus($accountInfo);
        }

        $this->getLogger()->info("更新数据处理完成", [
            'processed_count' => count($accounts)
        ]);
    }

    protected function processDeleteData(array $data): void
    {
//        $accounts = $data['value'] ?? [];

        $this->getLogger()->info("开始处理删除数据", [
            'total_accounts' => count($data),
            'full_data'      => $data
        ]);

        // 检查数据是否为空或null
        if (empty($data)) {
            $this->getLogger()->info("删除数据为空，跳过处理", [
                'value_type'    => gettype($data),
                'value_content' => $data
            ]);
            return;
        }

        // 标记账号为已登出
        foreach ($data as $index => $accountInfo) {
            $this->getLogger()->debug("处理删除账号", [
                'index'        => $index,
                'account_info' => $accountInfo
            ]);
            $this->markAccountLoggedOut($accountInfo['key']);
        }

        $this->getLogger()->info("删除数据处理完成", [
            'processed_count' => count($data)
        ]);
    }

    protected function updateAccountStatus(array $accountInfo): void
    {
        $username = $accountInfo['username'] ?? 'unknown';

        $this->getLogger()->debug("开始更新账号状态", [
            'username'     => $username,
            'account_info' => $accountInfo
        ]);

        try {
            DB::transaction(function () use ($accountInfo, $username) {
                $account = ItunesTradeAccount::withTrashed()->where('account', $username)->first();

                if ($account) {
                    $newLoginStatus = $accountInfo['code'] === 0
                        ? ItunesTradeAccount::STATUS_LOGIN_ACTIVE
                        : ItunesTradeAccount::STATUS_LOGIN_INVALID;

                    // 处理金额：移除货币符号并转换为数字
                    $amount = null;
                    if (isset($accountInfo['balance'])) {
                        $balanceStr = $accountInfo['balance'];
                        // 移除货币符号（$, ¥, €等）和逗号，保留数字和小数点
                        $cleanBalance = preg_replace('/[^\d.]/', '', $balanceStr);
                        $amount       = is_numeric($cleanBalance) ? (float)$cleanBalance : null;
                    }

                    $updateData = [
                        'login_status' => $newLoginStatus,
                        'country_code' => $accountInfo['country_code'] ?? $accountInfo['countryCode'] ?? 'US', // 默认US
                    ];

                    // 只有当金额不为null时才更新
                    if ($amount !== null) {
                        $updateData['amount'] = $amount;
                    }

                    $account->update($updateData);

                    $this->getLogger()->info("账号状态更新成功", [
                        'username'         => $username,
                        'old_login_status' => $account->getOriginal('login_status'),
                        'new_login_status' => $newLoginStatus,
                        'update_data'      => $updateData
                    ]);
                } else {
                    $this->getLogger()->warning("账号不存在，无法更新状态", [
                        'username'     => $username,
                        'account_info' => $accountInfo
                    ]);
                }
            });
        } catch (\Exception $e) {
            $this->getLogger()->error("更新账号状态失败", [
                'username'     => $username,
                'error'        => $e->getMessage(),
                'trace'        => $e->getTraceAsString(),
                'account_info' => $accountInfo
            ]);
        }
    }

    protected function markAccountLoggedOut(string $username): void
    {
        $this->getLogger()->debug("开始标记账号登出", [
            'username' => $username
        ]);

        try {
            DB::transaction(function () use ($username) {
                $account = ItunesTradeAccount::withTrashed()->where('account', $username)->first();

                if ($account) {
                    $oldLoginStatus = $account->login_status;
                    $account->update([
                        'login_status' => ItunesTradeAccount::STATUS_LOGIN_INVALID,
                    ]);

                    $this->getLogger()->info("账号登出状态更新成功", [
                        'username'         => $username,
                        'old_login_status' => $oldLoginStatus,
                        'new_login_status' => ItunesTradeAccount::STATUS_LOGIN_INVALID
                    ]);
                } else {
                    $this->getLogger()->warning("账号不存在，无法标记登出", [
                        'username' => $username
                    ]);
                }
            });
        } catch (\Exception $e) {
            $this->getLogger()->error("标记账号登出失败", [
                'username' => $username,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString()
            ]);
        }
    }

    protected function reconnect(): void
    {
        $delay = config('account_monitor.websocket.reconnect_delay', 5);

        $this->getLogger()->info("计划WebSocket重连", [
            'reconnect_delay' => $delay,
            'websocket_url'   => config('account_monitor.websocket.url') . '/api/ws?token=' . $this->token,
            'timestamp'       => now()->toISOString()
        ]);

        $this->loop->addTimer($delay, function () {
            $this->getLogger()->info("开始WebSocket重连", [
                'timestamp' => now()->toISOString()
            ]);
            $this->startMonitoring();
        });
    }

    public function run(): void
    {
        $this->getLogger()->info("启动事件循环", [
            'timestamp' => now()->toISOString()
        ]);

        $this->loop->run();
    }


}
