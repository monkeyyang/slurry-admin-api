<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\ItunesAccountVerify;
use App\Models\OperationLog;
use App\Services\EncryptionService;
use App\Services\ProxyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessVerifyCodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2分钟超时
    public $tries = 1; // 只尝试一次

    protected $roomId;
    protected $msgId;
    protected $wxid;
    protected $accounts;
    protected $uid;

    /**
     * Create a new job instance.
     */
    public function __construct($roomId, $msgId, $wxid, $accounts, $uid = null)
    {
        $this->roomId = $roomId;
        $this->msgId = $msgId;
        $this->wxid = $wxid;
        $this->accounts = $accounts;
        $this->uid = $uid;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        Log::info('开始处理查码任务', [
            'room_id' => $this->roomId,
            'msg_id' => $this->msgId,
            'wxid' => $this->wxid,
            'accounts_count' => count($this->accounts)
        ]);

        // 并发处理多个账号
        $promises = [];
        foreach ($this->accounts as $account) {
            $promises[] = $this->processSingleAccount($account);
        }

        // 等待所有查码完成
        $results = [];
        foreach ($promises as $promise) {
            $results[] = $promise;
        }

        Log::info('查码任务完成', [
            'room_id' => $this->roomId,
            'results_count' => count($results)
        ]);
    }

    /**
     * 处理单个账号的查码
     */
    protected function processSingleAccount($account)
    {
        try {
            // 查找账号对应的验证码地址
            $accountModel = ItunesAccountVerify::where('account', $account)->first();
            
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
            Log::error('查码异常', [
                'account' => $account,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
        $timeout = config('proxy.verify_timeout', 60); // 从配置获取超时时间
        $interval = config('proxy.verify_interval', 5); // 从配置获取间隔时间

        while (time() - $startTime < $timeout) {
            try {
                $response = $this->makeRequest($verifyUrl);
                
                if ($response['success']) {
                    $data = $response['data'];
                    
                    // 检查响应格式
                    if (isset($data['code']) && $data['code'] === 0) {
                        $verifyCode = $data['data']['code'] ?? '';
                        
                        if (!empty($verifyCode)) {
                            // 查码成功
                            $this->logOperation($account, 'success', '查码成功: ' . $verifyCode);
                            
                            return [
                                'account' => $account,
                                'status' => 'success',
                                'verify_code' => $verifyCode,
                                'code_time' => $data['data']['code_time'] ?? '',
                                'expired_date' => $data['data']['expired_date'] ?? ''
                            ];
                        } else {
                            // 验证码为空，继续等待
                            Log::info('验证码为空，继续等待', ['account' => $account]);
                        }
                    } else {
                        // 响应格式错误
                        Log::warning('查码响应格式错误', [
                            'account' => $account,
                            'response' => $data
                        ]);
                    }
                } else {
                    // 请求失败
                    Log::warning('查码请求失败', [
                        'account' => $account,
                        'error' => $response['error']
                    ]);
                }

            } catch (\Exception $e) {
                Log::error('查码请求异常', [
                    'account' => $account,
                    'error' => $e->getMessage()
                ]);
            }

            // 等待5秒后继续
            if (time() - $startTime < $timeout) {
                sleep($interval);
            }
        }

        // 超时
        $this->logOperation($account, 'failed', '查码超时');
        
        return [
            'account' => $account,
            'status' => 'timeout',
            'message' => '查码超时'
        ];
    }

    /**
     * 发送HTTP请求
     */
    protected function makeRequest($url)
    {
        try {
            // 这里可以配置代理IP
            $proxy = $this->getProxy();
            
            $response = Http::timeout(config('proxy.request_timeout', 10))
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept' => 'application/json, text/plain, */*',
                    'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                    'Cache-Control' => 'no-cache',
                ]);

            // 如果配置了代理，使用代理
            if ($proxy) {
                $response = $response->withOptions([
                    'proxy' => $proxy
                ]);
            }

            $httpResponse = $response->get($url);
            
            if ($httpResponse->successful()) {
                return [
                    'success' => true,
                    'data' => $httpResponse->json()
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'HTTP ' . $httpResponse->status()
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 获取代理IP
     */
    protected function getProxy()
    {
        return ProxyService::getProxy();
    }

    /**
     * 记录操作日志
     */
    protected function logOperation($account, $result, $details)
    {
        try {
            OperationLog::create([
                'uid' => $this->uid,
                'room_id' => $this->roomId,
                'wxid' => $this->wxid,
                'operation_type' => 'getVerifyCode',
                'target_account' => $account,
                'result' => $result,
                'details' => $details,
                'ip_address' => request()->ip() ?? '127.0.0.1',
                'user_agent' => request()->header('User-Agent') ?? 'Job Process',
            ]);
        } catch (\Exception $e) {
            Log::error('记录操作日志失败', [
                'error' => $e->getMessage(),
                'account' => $account,
                'result' => $result,
                'details' => $details
            ]);
        }
    }
} 