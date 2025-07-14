<?php

namespace App\Jobs;

use App\Models\ItunesTradeAccount;
use App\Services\GiftCardApiClient;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Apple账号登录队列任务
 *
 * 特性：
 * - 每日最多重试3次
 * - 退避机制：首次失败30分钟后重试，二次失败1小时后重试
 * - 三次失败后发送微信通知
 */
class ProcessAppleAccountLoginJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $accountId;
    private string $reason;
    private int $currentAttempt;

    // 重试间隔（分钟）
    private const RETRY_DELAYS = [
        1 => 30,  // 第一次重试：30分钟后
        2 => 60,  // 第二次重试：1小时后
    ];

    // 每日最大重试次数
    private const MAX_DAILY_ATTEMPTS = 3;

    /**
     * 创建新的任务实例
     */
    public function __construct(int $accountId, string $reason = 'system_request', int $currentAttempt = 1)
    {
        $this->accountId = $accountId;
        $this->reason = $reason;
        $this->currentAttempt = $currentAttempt;

        // 设置队列和延迟
        $this->onQueue('account_login_operations');

        // 如果是重试，添加延迟
        if ($currentAttempt > 1 && isset(self::RETRY_DELAYS[$currentAttempt - 1])) {
            $delayMinutes = self::RETRY_DELAYS[$currentAttempt - 1];
            $this->delay(now()->addMinutes($delayMinutes));
        }
    }

    /**
     * 执行任务
     */
    public function handle(): void
    {
        $account = ItunesTradeAccount::find($this->accountId);

        if (!$account) {
            Log::warning("登录任务：账号不存在", ['account_id' => $this->accountId]);
            return;
        }

        // 防重复处理：获取账号处理锁
        $lockKey = "login_processing_" . $this->accountId;
        $lockTtl = 600; // 10分钟锁定时间

        if (!Cache::add($lockKey, $this->job->uuid(), $lockTtl)) {
            Log::info("账号 {$account->account} 正在被其他任务处理，跳过", [
                'account_id' => $this->accountId,
                'job_uuid' => $this->job->uuid()
            ]);
            return;
        }

        try {
            $this->processAccountLogin($account);
        } finally {
            // 确保锁被释放
            Cache::forget($lockKey);
        }
    }

    /**
     * 处理账号登录
     */
    private function processAccountLogin(ItunesTradeAccount $account): void
    {
        // 检查今日重试次数
        $todayAttempts = $this->getTodayAttempts($account->account);

        if ($todayAttempts >= self::MAX_DAILY_ATTEMPTS) {
            Log::warning("账号 {$account->account} 今日登录重试次数已达上限", [
                'account' => $account->account,
                'today_attempts' => $todayAttempts,
                'max_attempts' => self::MAX_DAILY_ATTEMPTS
            ]);
            return;
        }

        // 如果已经是活跃状态，跳过
        if ($account->login_status === ItunesTradeAccount::STATUS_LOGIN_ACTIVE) {
            Log::info("账号 {$account->account} 已经是登录状态，跳过");
            return;
        }

        try {
            Log::info("开始处理账号登录", [
                'account' => $account->account,
                'account_id' => $this->accountId,
                'reason' => $this->reason,
                'attempt' => $this->currentAttempt,
                'today_attempts' => $todayAttempts + 1
            ]);

            $giftCardApiClient = app(GiftCardApiClient::class);

            // 准备登录数据
            $loginData = [[
                'id' => $account->id,
                'username' => $account->account,
                'password' => $account->getDecryptedPassword(),
                'VerifyUrl' => $account->api_url ?? ''
            ]];

            // 调用登录API创建任务
            $response = $giftCardApiClient->createLoginTask($loginData);

            if ($response['code'] !== 0) {
                $errorMsg = $response['msg'] ?? '创建登录任务失败';

                Log::error("❌ 账号 {$account->account} 登录任务创建失败", [
                    'error_code' => $response['code'],
                    'error_msg' => $errorMsg,
                    'attempt' => $this->currentAttempt
                ]);

                $this->handleLoginFailure($account, $errorMsg, $todayAttempts);
                return;
            }

            $taskId = $response['data']['task_id'] ?? null;
            if (!$taskId) {
                $errorMsg = '登录任务创建成功但未收到任务ID';
                Log::error("❌ {$errorMsg}", ['account' => $account->account]);
                $this->handleLoginFailure($account, $errorMsg, $todayAttempts);
                return;
            }

            Log::info("✅ 账号 {$account->account} 登录任务创建成功，开始轮询状态", [
                'task_id' => $taskId,
                'attempt' => $this->currentAttempt
            ]);

            // 轮询登录任务状态直到完成
            $finalResult = $this->pollLoginTaskStatus($giftCardApiClient, $taskId, $account);

            // 根据最终结果处理
            if ($finalResult['success']) {
                Log::info("✅ 账号 {$account->account} 登录成功", [
                    'task_id' => $taskId,
                    'result' => $finalResult['result']
                ]);

                // 登录成功，清除重试记录
                $this->clearAttempts($account->account);

                // 更新账号状态和余额信息
                $this->updateAccountFromLoginResult($account, $finalResult['result']);

            } else {
                Log::error("❌ 账号 {$account->account} 登录失败", [
                    'task_id' => $taskId,
                    'error_msg' => $finalResult['error'],
                    'attempt' => $this->currentAttempt
                ]);

                $this->handleLoginFailure($account, $finalResult['error'], $todayAttempts);
            }

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();

            Log::error("❌ 账号 {$account->account} 登录任务异常", [
                'error' => $errorMsg,
                'attempt' => $this->currentAttempt,
                'trace' => $e->getTraceAsString()
            ]);

            $this->handleLoginFailure($account, $errorMsg, $todayAttempts);
        }
    }

    /**
     * 轮询登录任务状态直到完成
     */
    private function pollLoginTaskStatus(GiftCardApiClient $giftCardApiClient, string $taskId, ItunesTradeAccount $account): array
    {
        $maxWaitTime = 300; // 最大等待5分钟
        $pollInterval = 0.2; // 200ms轮询间隔
        $startTime = time();

        Log::info("开始轮询登录任务状态", [
            'task_id' => $taskId,
            'account' => $account->account,
            'max_wait_time' => $maxWaitTime,
            'poll_interval' => $pollInterval
        ]);

        while (time() - $startTime < $maxWaitTime) {
            try {
                $statusResponse = $giftCardApiClient->getLoginTaskStatus($taskId);

                if ($statusResponse['code'] !== 0) {
                    Log::error("查询登录任务状态失败", [
                        'task_id' => $taskId,
                        'account' => $account->account,
                        'error' => $statusResponse['msg'] ?? 'unknown'
                    ]);

                    return [
                        'success' => false,
                        'error' => '查询任务状态失败: ' . ($statusResponse['msg'] ?? 'unknown')
                    ];
                }

                $taskStatus = $statusResponse['data']['status'] ?? '';
                $items = $statusResponse['data']['items'] ?? [];

                Log::debug("登录任务状态", [
                    'task_id' => $taskId,
                    'account' => $account->account,
                    'task_status' => $taskStatus,
                    'items_count' => count($items),
                    'elapsed_time' => time() - $startTime . 's'
                ]);

                // 检查任务是否完成
                if ($taskStatus === 'completed') {
                    // 查找当前账号的结果
                    foreach ($items as $item) {
                        if ($item['data_id'] === $account->account) {
                            return $this->parseLoginResult($item, $account);
                        }
                    }

                    return [
                        'success' => false,
                        'error' => '任务完成但未找到账号结果'
                    ];
                }

                // 如果还在处理中，继续等待
                if (in_array($taskStatus, ['pending', 'running'])) {
                    usleep($pollInterval * 1000000); // 转换为微秒
                    continue;
                }

                // 未知状态
                return [
                    'success' => false,
                    'error' => '未知任务状态: ' . $taskStatus
                ];

            } catch (\Exception $e) {
                Log::error("轮询登录任务状态异常", [
                    'task_id' => $taskId,
                    'account' => $account->account,
                    'error' => $e->getMessage()
                ]);

                return [
                    'success' => false,
                    'error' => '轮询状态异常: ' . $e->getMessage()
                ];
            }
        }

        // 超时
        Log::error("轮询登录任务状态超时", [
            'task_id' => $taskId,
            'account' => $account->account,
            'wait_time' => time() - $startTime . 's'
        ]);

        return [
            'success' => false,
            'error' => '轮询任务状态超时'
        ];
    }

    /**
     * 解析登录结果
     */
    private function parseLoginResult(array $item, ItunesTradeAccount $account): array
    {
        $status = $item['status'] ?? '';
        $msg = $item['msg'] ?? '';
        $result = $item['result'] ?? '';

        Log::info("解析登录结果", [
            'account' => $account->account,
            'status' => $status,
            'msg' => $msg,
            'has_result' => !empty($result)
        ]);

        if ($status !== 'completed') {
            return [
                'success' => false,
                'error' => "任务未完成，状态: {$status}, 消息: {$msg}"
            ];
        }

        // 解析result字段中的JSON数据
        if (empty($result)) {
            return [
                'success' => false,
                'error' => "登录完成但无结果数据，消息: {$msg}"
            ];
        }

        try {
            $resultData = json_decode($result, true);

            if (!$resultData) {
                return [
                    'success' => false,
                    'error' => "无法解析登录结果数据，消息: {$msg}"
                ];
            }

            $code = $resultData['code'] ?? -1;
            $resultMsg = $resultData['msg'] ?? $msg;

            // code 为 0 表示成功
            if ($code === 0) {
                return [
                    'success' => true,
                    'result' => $resultData,
                    'msg' => $resultMsg
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $resultMsg
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "解析登录结果异常: " . $e->getMessage() . ", 原始消息: {$msg}"
            ];
        }
    }

    /**
     * 根据登录结果更新账号信息
     */
    private function updateAccountFromLoginResult(ItunesTradeAccount $account, array $resultData): void
    {
        try {
            $updates = ['login_status' => ItunesTradeAccount::STATUS_LOGIN_ACTIVE];

            // 更新余额
            if (isset($resultData['balance']) && $resultData['balance'] !== '') {
                $balance = (float)preg_replace('/[^\d.-]/', '', $resultData['balance']);
                $updates['amount'] = $balance;

                Log::info("更新账号余额", [
                    'account' => $account->account,
                    'old_balance' => $account->amount,
                    'new_balance' => $balance,
                    'balance_string' => $resultData['balance']
                ]);
            }

            // 更新国家信息
            if (isset($resultData['countryCode']) && !empty($resultData['countryCode'])) {
                $updates['country_code'] = $resultData['countryCode'];

                Log::info("更新账号国家信息", [
                    'account' => $account->account,
                    'country_code' => $resultData['countryCode'],
                    'country' => $resultData['country'] ?? 'unknown'
                ]);
            }

            $account->update($updates);

        } catch (\Exception $e) {
            Log::error("更新账号信息失败", [
                'account' => $account->account,
                'error' => $e->getMessage(),
                'result_data' => $resultData
            ]);
        }
    }

    /**
     * 统一处理登录失败
     */
    private function handleLoginFailure(ItunesTradeAccount $account, string $errorMsg, int $todayAttempts): void
    {
        // 记录本次尝试
        $this->recordAttempt($account->account);

        // 检查是否需要重试
        if ($this->currentAttempt < self::MAX_DAILY_ATTEMPTS) {
            $this->scheduleRetry($account, $errorMsg);
        } else {
            $this->sendFailureNotification($account, $errorMsg, $todayAttempts + 1);
        }
    }

    /**
     * 获取今日重试次数
     */
    private function getTodayAttempts(string $account): int
    {
        $cacheKey = "login_attempts_" . md5($account) . "_" . now()->format('Y-m-d');
        return (int) Cache::get($cacheKey, 0);
    }

    /**
     * 记录重试次数
     */
    private function recordAttempt(string $account): void
    {
        $cacheKey = "login_attempts_" . md5($account) . "_" . now()->format('Y-m-d');
        $attempts = $this->getTodayAttempts($account) + 1;

        // 缓存到明天凌晨
        $expiresAt = now()->addDay()->startOfDay();
        Cache::put($cacheKey, $attempts, $expiresAt);
    }

    /**
     * 清除重试记录
     */
    private function clearAttempts(string $account): void
    {
        $cacheKey = "login_attempts_" . md5($account) . "_" . now()->format('Y-m-d');
        Cache::forget($cacheKey);
    }

    /**
     * 安排重试
     */
    private function scheduleRetry(ItunesTradeAccount $account, string $errorMsg): void
    {
        $nextAttempt = $this->currentAttempt + 1;
        $delayMinutes = self::RETRY_DELAYS[$this->currentAttempt] ?? 60;

        Log::info("📅 安排账号 {$account->account} 重试登录", [
            'next_attempt' => $nextAttempt,
            'delay_minutes' => $delayMinutes,
            'error_msg' => $errorMsg
        ]);

        // 安排下次重试
        self::dispatch($this->accountId, $this->reason, $nextAttempt);
    }

    /**
     * 发送失败通知
     */
    private function sendFailureNotification(ItunesTradeAccount $account, string $errorMsg, int $totalAttempts): void
    {
        Log::error("账号 {$account->account} 登录失败达到最大重试次数", [
            'account' => $account->account,
            'total_attempts' => $totalAttempts,
            'error_msg' => $errorMsg
        ]);

        // 发送微信通知
        $msg = "[警告]账号登录失败通知\n";
        $msg .= "---------------------------------\n";
        $msg .= "账号：{$account->account}\n";
        $msg .= "国家：{$account->country_code}\n";
        $msg .= "重试次数：{$totalAttempts}\n";
        $msg .= "失败原因：{$errorMsg}\n";
        $msg .= "时间：" . now()->format('Y-m-d H:i:s');

        try {
            send_msg_to_wechat('45958721463@chatroom', $msg);
        } catch (\Exception $e) {
            Log::error("发送微信通知失败: " . $e->getMessage());
        }
    }

    /**
     * 任务失败时的处理
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("登录任务彻底失败", [
            'account_id' => $this->accountId,
            'attempt' => $this->currentAttempt,
            'error' => $exception->getMessage()
        ]);
    }
}
