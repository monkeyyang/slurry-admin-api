<?php

namespace App\Jobs;

use App\Models\ItunesTradeAccount;
use App\Services\GiftCardApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Apple账号登出队列任务
 */
class ProcessAppleAccountLogoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int    $accountId;
    private string $reason;

    /**
     * 创建新的任务实例
     */
    public function __construct(int $accountId, string $reason = 'system_request')
    {
        $this->accountId = $accountId;
        $this->reason    = $reason;

        // 设置队列
        $this->onQueue('account_operations');
    }

    /**
     * 执行任务
     */
    public function handle(): void
    {
        $account = ItunesTradeAccount::find($this->accountId);

        if (!$account) {
            Log::warning("登出任务：账号不存在", ['account_id' => $this->accountId]);
            return;
        }

        // 如果已经是无效状态，跳过
        if ($account->login_status === ItunesTradeAccount::STATUS_LOGIN_INVALID) {
            Log::info("账号 {$account->account} 已经是登出状态，跳过");
            return;
        }

        try {
            Log::info("开始处理账号登出", [
                'account'    => $account->account,
                'account_id' => $this->accountId,
                'reason'     => $this->reason
            ]);

            $giftCardApiClient = app(GiftCardApiClient::class);

            // 准备登出数据
            $logoutData = [['username' => $account->account]];

            // 调用登出API
            $response = $giftCardApiClient->deleteUserLogins($logoutData);

            if ($response['code'] === 0) {
                // 更新账号状态
                $account->update(['login_status' => ItunesTradeAccount::STATUS_LOGIN_INVALID]);

                Log::info("✅ 账号 {$account->account} 登出成功", [
                    'reason' => $this->reason
                ]);

            } else {
                Log::error("❌ 账号 {$account->account} 登出失败", [
                    'error_code' => $response['code'] ?? 'unknown',
                    'error_msg'  => $response['msg'] ?? '未知错误',
                    'reason'     => $this->reason
                ]);
            }

        } catch (\Exception $e) {
            Log::error("❌ 账号 {$account->account} 登出任务异常", [
                'error'  => $e->getMessage(),
                'reason' => $this->reason,
                'trace'  => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 任务失败时的处理
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("登出任务失败", [
            'account_id' => $this->accountId,
            'reason'     => $this->reason,
            'error'      => $exception->getMessage()
        ]);
    }
}
