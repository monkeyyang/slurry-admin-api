<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessVerifyCodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120; // 2分钟超时
    public int $tries   = 1;   // 只尝试一次

    protected string $roomId;
    protected string $msgId;
    protected string $wxid;
    protected        $accounts;
    protected ?int   $uid;

    /**
     * Create a new job instance.
     */
    public function __construct($roomId, $msgId, $wxid, $accounts, $uid = null)
    {
        $this->roomId   = $roomId;
        $this->msgId    = $msgId;
        $this->wxid     = $wxid;
        $this->accounts = $accounts;
        $this->uid      = $uid;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        Log::channel('verify_code_job')->info('开始处理查码任务', [
            'room_id'        => $this->roomId,
            'msg_id'         => $this->msgId,
            'wxid'           => $this->wxid,
            'accounts_count' => count($this->accounts)
        ]);

        // 检查是否支持pcntl扩展
        if (!extension_loaded('pcntl')) {
            Log::channel('verify_code_job')->warning('pcntl扩展未安装，使用串行处理');
            $this->processAccountsSequentially();
            return;
        }

        // 并发处理多个账号
        $results   = [];
        $processes = [];

        foreach ($this->accounts as $account) {
            // 为每个账号创建子进程
            $process             = $this->createChildProcess($account);
            $processes[$account] = $process;
        }

        // 等待所有子进程完成
        foreach ($processes as $account => $pid) {
            $result    = $this->waitForChildProcess($pid, $account);
            $results[] = $result;
        }

        Log::channel('verify_code_job')->info('查码任务完成', [
            'room_id'       => $this->roomId,
            'results_count' => count($results)
        ]);
    }

    /**
     * 创建子进程处理单个账号
     */
    protected function createChildProcess($account)
    {
        $pid = pcntl_fork();

        if ($pid == -1) {
            // 创建进程失败，使用同步处理
            Log::channel('verify_code_job')->warning('无法创建子进程，使用同步处理', ['account' => $account]);
            return $this->processSingleAccount($account);
        } elseif ($pid == 0) {
            // 子进程
            try {
                $result = $this->processSingleAccount($account);
                // 将结果写入临时文件
                $tempFile = sys_get_temp_dir() . '/verify_code_' . $account . '_' . getmypid() . '.json';
                file_put_contents($tempFile, json_encode($result));
                exit(0);
            } catch (\Exception $e) {
                Log::channel('verify_code_job')->error('子进程异常', [
                    'account' => $account,
                    'error'   => $e->getMessage()
                ]);
                exit(1);
            }
        } else {
            // 父进程，返回进程ID
            Log::channel('verify_code_job')->info('创建子进程成功', [
                'account' => $account,
                'pid'     => $pid
            ]);
            return $pid;
        }
    }

    /**
     * 等待子进程完成
     */
    protected function waitForChildProcess($pid, $account)
    {
        if (is_array($pid)) {
            // 同步处理的结果
            return $pid;
        }

        // 等待子进程完成
        $status = 0;
        pcntl_waitpid($pid, $status);

        // 检查进程是否正常退出
        if (pcntl_wexitstatus($status) == 0) {
            // 尝试读取结果文件
            $tempFile = sys_get_temp_dir() . '/verify_code_' . $account . '_' . $pid . '.json';
            if (file_exists($tempFile)) {
                $result = json_decode(file_get_contents($tempFile), true);
                unlink($tempFile); // 删除临时文件
                Log::channel('verify_code_job')->info('子进程完成', [
                    'account' => $account,
                    'pid'     => $pid,
                    'result'  => $result
                ]);
                return $result;
            } else {
                Log::channel('verify_code_job')->warning('子进程完成但未找到结果文件', [
                    'account' => $account,
                    'pid'     => $pid
                ]);
                return ['account' => $account, 'status' => 'completed', 'message' => '子进程处理完成'];
            }
        } else {
            Log::channel('verify_code_job')->error('子进程异常退出', [
                'account' => $account,
                'pid'     => $pid,
                'status'  => $status
            ]);
            return ['account' => $account, 'status' => 'failed', 'message' => '子进程异常退出'];
        }
    }

    /**
     * 串行处理账号（备用方案）
     */
    protected function processAccountsSequentially()
    {
        foreach ($this->accounts as $account) {
            $result = $this->processSingleAccount($account);
            Log::channel('verify_code_job')->info('串行处理完成', [
                'account' => $account,
                'result'  => $result
            ]);
        }
    }

    /**
     * 处理单个账号的查码
     */
    protected function processSingleAccount($account)
    {
        try {
            // 查找账号对应的验证码地址
            $accountModel = \App\Models\ItunesAccountVerify::where('account', $account)->first();

            if (!$accountModel) {
                $this->logOperation($account, 'failed', '账号不存在');
                return ['account' => $account, 'status' => 'failed', 'message' => '账号不存在'];
            }

            $verifyUrl = $accountModel->verify_url;
            if (empty($verifyUrl)) {
                $this->logOperation($account, 'failed', '验证码地址为空');
                return ['account' => $account, 'status' => 'failed', 'message' => '验证码地址为空'];
            }

            // 开始查码
            $result = $this->fetchVerifyCode($verifyUrl, $account);

            return $result;

        } catch (\Exception $e) {
            Log::channel('verify_code_job')->error('查码异常', [
                'account' => $account,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);

            $this->logOperation($account, 'failed', '查码异常: ' . $e->getMessage());

            return ['account' => $account, 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * 获取验证码
     */
    protected function fetchVerifyCode($verifyUrl, $account)
    {
        $startTime = time();
        $timeout   = config('proxy.verify_timeout', 60);
        $interval  = config('proxy.verify_interval', 5);

        Log::channel('verify_code_job')->info('开始查码', [
            'account'    => $account,
            'verify_url' => $verifyUrl,
            'timeout'    => $timeout,
            'interval'   => $interval,
            'start_time' => $startTime
        ]);

        while (time() - $startTime < $timeout) {
            try {
                $response = $this->makeRequest($verifyUrl);

                if ($response['success']) {
                    $data = $response['data'];

                    // 检查响应格式：code为1表示成功，0表示失败
                    if (isset($data['code']) && $data['code'] === 1) {
                        $verifyCode = $data['data']['code'] ?? '';

                        if (!empty($verifyCode)) {
                            // 尝试提取纯数字验证码
                            $pureCode = $this->extractPureCode($verifyCode);

                            $this->logOperation($account, 'success', '查码成功: ' . $pureCode);

                            // 发送微信消息
                            $this->sendWechatMessage($account, $pureCode);

                            return [
                                'account'       => $account,
                                'status'        => 'success',
                                'verify_code'   => $pureCode,
                                'original_code' => $verifyCode,
                                'code_time'     => $data['data']['code_time'] ?? '',
                                'expired_date'  => $data['data']['expired_date'] ?? ''
                            ];
                        } else {
                            Log::channel('verify_code_job')->info('验证码为空，继续等待', ['account' => $account]);
                        }
                    } elseif (isset($data['code']) && $data['code'] === 0) {
                        // code为0表示失败，记录错误信息
                        $errorMsg = $data['msg'] ?? '查码失败';
                        Log::channel('verify_code_job')->warning('查码失败', [
                            'account'  => $account,
                            'error'    => $errorMsg,
                            'response' => $data
                        ]);
                    } else {
                        Log::channel('verify_code_job')->warning('查码响应格式错误', [
                            'account'  => $account,
                            'response' => $data
                        ]);
                    }
                } else {
                    Log::channel('verify_code_job')->warning('查码请求失败', [
                        'account' => $account,
                        'error'   => $response['error']
                    ]);
                }

            } catch (\Exception $e) {
                Log::channel('verify_code_job')->error('查码请求异常', [
                    'account' => $account,
                    'error'   => $e->getMessage()
                ]);
            }

            if (time() - $startTime < $timeout) {
                sleep($interval);
            }
        }

        Log::channel('verify_code_job')->warning('查码超时', [
            'account'      => $account,
            'elapsed_time' => time() - $startTime,
            'timeout'      => $timeout
        ]);

        $this->logOperation($account, 'failed', '查码超时');

        // 发送微信消息（超时）
        $this->sendWechatMessage($account, '查码超时');

        return [
            'account' => $account,
            'status'  => 'timeout',
            'message' => '查码超时'
        ];
    }

    /**
     * 发送HTTP请求
     */
    protected function makeRequest($url)
    {
        try {
            $proxy = \App\Services\ProxyService::getProxy();

            $response = \Illuminate\Support\Facades\Http::timeout(config('proxy.request_timeout', 10))
                ->withHeaders([
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept'          => 'application/json, text/plain, */*',
                    'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                    'Cache-Control'   => 'no-cache',
                ]);

            if ($proxy) {
                $response = $response->withOptions([
                    'proxy' => $proxy
                ]);
            }

            $httpResponse = $response->get($url);

            if ($httpResponse->successful()) {
                return [
                    'success' => true,
                    'data'    => $httpResponse->json()
                ];
            } else {
                return [
                    'success' => false,
                    'error'   => 'HTTP ' . $httpResponse->status()
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }

    /**
     * 提取纯数字验证码
     */
    protected function extractPureCode($verifyCode)
    {
        // 尝试多种模式提取纯数字验证码
        $patterns = [
            '/#(\d+)/',           // #936177
            '/代码为：(\d+)/',     // 代码为：936177
            '/验证码[：:]\s*(\d+)/', // 验证码：936177
            '/(\d{6})/',          // 6位数字
            '/(\d{4,8})/',        // 4-8位数字
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $verifyCode, $matches)) {
                $pureCode = $matches[1];
                Log::channel('verify_code_job')->info('验证码提取成功', [
                    'original'  => $verifyCode,
                    'extracted' => $pureCode,
                    'pattern'   => $pattern
                ]);
                return $pureCode;
            }
        }

        // 如果无法提取，返回原始验证码
        Log::channel('verify_code_job')->warning('无法提取纯数字验证码，使用原始验证码', [
            'original' => $verifyCode
        ]);
        return $verifyCode;
    }

    /**
     * 发送微信消息
     */
    protected function sendWechatMessage($account, $code)
    {
        try {
            $roomId  = '20229649389@chatroom';
            $account = strtolower($account);
            // 根据code内容判断是成功还是失败
            if ($code === '查码超时') {
                // 失败消息格式
                $msg = "❌ 查码失败\n---------------------\n{$account}\n{$code}";
            } else {
                // 成功消息格式
                $msg = "✅ 查码成功\n---------------------\n{$account}\n{$code}";
            }

            // 调用微信发送函数
            send_msg_to_wechat($roomId, $msg);

            Log::channel('verify_code_job')->info('微信消息发送成功', [
                'account' => $account,
                'code'    => $code,
                'message' => $msg,
                'room_id' => $roomId
            ]);

        } catch (\Exception $e) {
            Log::channel('verify_code_job')->error('微信消息发送失败', [
                'error'   => $e->getMessage(),
                'account' => $account,
                'code'    => $code,
                'room_id' => $roomId
            ]);
        }
    }

    /**
     * 记录操作日志
     */
    protected function logOperation($account, $result, $details)
    {
        try {
            \App\Models\OperationLog::create([
                'uid'            => $this->uid ?? 0, // 如果uid为null，使用0作为默认值
                'room_id'        => $this->roomId,
                'wxid'           => $this->wxid,
                'operation_type' => 'getVerifyCode',
                'target_account' => $account,
                'result'         => $result,
                'details'        => $details,
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->header('User-Agent') ?? 'Job Process',
            ]);
        } catch (\Exception $e) {
            Log::channel('verify_code_job')->error('记录操作日志失败', [
                'error'   => $e->getMessage(),
                'account' => $account,
                'result'  => $result,
                'details' => $details
            ]);
        }
    }


}
