<?php

namespace App\Console\Commands;

use App\Models\ChargePlan;
use App\Models\ChargePlanItem;
use App\Models\ChargePlanLog;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use App\Jobs\ProcessAppleAccountLogoutJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckPlanDayProgressCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plan:check-day-progress';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and update plan day progress for completed days after 24 hours';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('开始检查计划天数进度...');

        // 获取所有已完成且仍登录的账号
        $completedAccounts = ItunesTradeAccount::where('status', 'completed')
            ->where('login_status', 'valid')
            ->get();

        $this->info("找到 {$completedAccounts->count()} 个已完成但仍登录的账号");

        $logoutCount = 0;
        foreach ($completedAccounts as $account) {
            try {
                if ($this->checkCompletedAccount($account)) {
                    $logoutCount++;
                }
            } catch (\Exception $e) {
                $this->error("处理账号 {$account->account} 时发生错误: " . $e->getMessage());
                Log::channel('gift_card_exchange')->error("CheckPlanDayProgressCommand: 处理账号 {$account->account} 失败", [
                    'error'   => $e->getMessage(),
                    'account' => $account->id
                ]);
            }
        }


        $this->info("检查完成！");
        $this->info("- 已完成账号登出处理: {$logoutCount} 个");
    }

    /**
     * 检查已完成账号并处理登出和通知
     */
    private function checkCompletedAccount(ItunesTradeAccount $account): bool
    {
        $this->info("检查已完成账号: {$account->account}");

        // 验证账号确实已完成
        if (!$this->isAccountCompleted($account)) {
            $this->warn("账号 {$account->account} 状态为completed但未达到完成条件，跳过处理");
            return false;
        }

        // 获取最后一次成功兑换的时间
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        if (!$lastSuccessLog) {
            $this->warn("账号 {$account->account} 没有找到成功兑换记录，跳过处理");
            return false;
        }

        // 检查是否已过去足够时间（例如1小时）才进行登出处理
        $lastExchangeTime       = Carbon::parse($lastSuccessLog->exchange_time);
        $hoursSinceLastExchange = $lastExchangeTime->diffInHours(Carbon::now());

        if ($hoursSinceLastExchange < 1) {
            $this->info("账号 {$account->account} 最后兑换时间距离现在不足1小时，等待处理");
            return false;
        }

        // 请求登出此账号
        ProcessAppleAccountLogoutJob::dispatch($account->id, 'plan_completed_check');

        // 发送微信通知
        $this->sendCompletionNotification($account, $lastSuccessLog);

        $this->info("✅ 账号 {$account->account} 已请求登出并发送通知");

        Log::channel('gift_card_exchange')->info("CheckPlanDayProgressCommand: 账号完成后处理", [
            'account_id'                => $account->id,
            'account'                   => $account->account,
            'last_exchange_time'        => $lastExchangeTime->toDateTimeString(),
            'hours_since_last_exchange' => $hoursSinceLastExchange
        ]);

        return true;
    }

    /**
     * 检查账号是否真正完成
     */
    private function isAccountCompleted(ItunesTradeAccount $account): bool
    {
        // 获取最后一条成功兑换记录的after_amount（兑换后总金额）
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        if (!$lastSuccessLog) {
            return false;
        }

        $currentTotalAmount = $lastSuccessLog->after_amount;

        // 如果账号有计划，检查是否达到计划总额
        if ($account->plan) {
            return $currentTotalAmount >= $account->plan->total_amount;
        }

        // 如果没有计划，检查是否有足够的兑换记录
        return $currentTotalAmount > 0;
    }

    /**
     * 发送完成通知到微信
     */
    private function sendCompletionNotification(ItunesTradeAccount $account, ItunesTradeAccountLog $lastSuccessLog): void
    {
        $currentTotalAmount = $lastSuccessLog->after_amount;
        
        // 方法1：直接使用配置模板
        $template = config('wechat.templates.redeem_plan_completed');
        
        // 替换占位符
        $msg = str_replace([
            '{account}',
            '{country}',
            '{balance}'
        ], [
            $account->account,
            $account->country_code ?? 'Unknown',
            $currentTotalAmount
        ], $template);

        try {
            send_msg_to_wechat('45958721463@chatroom', $msg, 'MT_SEND_TEXTMSG', true, 'check-plan-progress');
            
            Log::channel('gift_card_exchange')->info("CheckPlanDayProgressCommand: 微信通知发送成功", [
                'account_id'   => $account->id,
                'account'      => $account->account,
                'total_amount' => $currentTotalAmount
            ]);
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error("CheckPlanDayProgressCommand: 微信通知发送失败", [
                'account_id' => $account->id,
                'account'    => $account->account,
                'error'      => $e->getMessage()
            ]);
        }
    }

    /**
     * 发送完成通知到微信（使用WechatMessageService模板功能）
     */
    private function sendCompletionNotificationWithService(ItunesTradeAccount $account, ItunesTradeAccountLog $lastSuccessLog): void
    {
        $currentTotalAmount = $lastSuccessLog->after_amount;
        
        // 方法2：使用WechatMessageService的模板功能
        $wechatMessageService = app(\App\Services\WechatMessageService::class);
        
        $result = $wechatMessageService->sendMessageWithTemplate(
            '45958721463@chatroom',
            'redeem_plan_completed',
            [
                'account' => $account->account,
                'country' => $account->country_code ?? 'Unknown',
                'balance' => $currentTotalAmount
            ],
            'check-plan-progress'
        );

        if ($result) {
            Log::channel('gift_card_exchange')->info("CheckPlanDayProgressCommand: 微信模板通知发送成功", [
                'account_id'   => $account->id,
                'account'      => $account->account,
                'total_amount' => $currentTotalAmount,
                'message_id'   => $result
            ]);
        } else {
            Log::channel('gift_card_exchange')->error("CheckPlanDayProgressCommand: 微信模板通知发送失败", [
                'account_id' => $account->id,
                'account'    => $account->account
            ]);
        }
    }

    /**
     * 发送完成通知到微信（使用辅助函数）
     */
    private function sendCompletionNotificationWithHelper(ItunesTradeAccount $account, ItunesTradeAccountLog $lastSuccessLog): void
    {
        $currentTotalAmount = $lastSuccessLog->after_amount;
        
        // 方法3：使用辅助函数（最简单的方式）
        $result = send_wechat_template(
            '45958721463@chatroom',
            'redeem_plan_completed',
            [
                'account' => $account->account,
                'country' => $account->country_code ?? 'Unknown',
                'balance' => $currentTotalAmount
            ],
            'check-plan-progress'
        );

        if ($result) {
            Log::channel('gift_card_exchange')->info("CheckPlanDayProgressCommand: 微信辅助函数通知发送成功", [
                'account_id'   => $account->id,
                'account'      => $account->account,
                'total_amount' => $currentTotalAmount,
                'message_id'   => $result
            ]);
        } else {
            Log::channel('gift_card_exchange')->error("CheckPlanDayProgressCommand: 微信辅助函数通知发送失败", [
                'account_id' => $account->id,
                'account'    => $account->account
            ]);
        }
    }

 
}
