<?php

namespace App\Services\Gift;

use App\Services\Gift\FindAccountService;
use App\Models\GiftCardExchangeRecord;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * 基于智能筛选的礼品卡兑换服务
 * 
 * 新的工作流程：
 * 1. 验证礼品卡获取基本信息（金额、国家）
 * 2. 根据汇率和计划配置查找最优账号
 * 3. 原子锁定账号并执行兑换
 * 4. 支持群聊绑定控制和各种约束验证
 */
class PoolBasedGiftCardService
{
    private FindAccountService $findAccountService;

    public function __construct(FindAccountService $findAccountService)
    {
        $this->findAccountService = $findAccountService;
    }

    /**
     * 兑换礼品卡（主入口）
     * 
     * @param string $giftCardCode 礼品卡编码
     * @param string|null $roomId 群聊ID
     * @param string $cardType 卡类型
     * @param string $cardForm 卡形式
     * @return array 兑换结果
     */
    public function exchangeGiftCard(string $giftCardCode, ?string $roomId = null, string $cardType = '1', string $cardForm = 'electronic'): array
    {
        Log::info("开始智能筛选礼品卡兑换", [
            'card_code' => $giftCardCode,
            'room_id' => $roomId,
            'card_type' => $cardType,
            'card_form' => $cardForm,
            'service' => 'PoolBasedGiftCardService_v2'
        ]);

        try {
            // 1. 验证礼品卡，获取基本信息
            $giftCardInfo = $this->validateGiftCard($giftCardCode);
            
            // 2. 根据礼品卡信息查找汇率和计划
            $rate = $this->findMatchingRate($giftCardInfo, $cardType, $cardForm);
            $plan = $this->findAvailablePlan($rate->id);
            
            // 3. 准备礼品卡信息（包含房间ID）
            $giftCardInfoWithRoom = array_merge($giftCardInfo, [
                'room_id' => $roomId,
                'card_type' => $cardType,
                'card_form' => $cardForm
            ]);

            // 4. 使用智能筛选查找最优账号
            $account = $this->findAccountService->findOptimalAccount(
                $plan,
                $roomId ?? '',
                $giftCardInfoWithRoom
            );

            if (!$account) {
                return [
                    'success' => false,
                    'error' => 'NO_AVAILABLE_ACCOUNT',
                    'message' => '没有可用的账号进行兑换',
                    'details' => [
                        'country' => $giftCardInfo['country_code'],
                        'amount' => $giftCardInfo['amount'],
                        'plan_id' => $plan->id,
                        'room_id' => $roomId
                    ]
                ];
            }

            Log::info("找到最优账号，开始兑换", [
                'account_id' => $account->id,
                'account_email' => $account->account,
                'account_balance' => $account->amount,
                'plan_id' => $plan->id,
                'room_id' => $roomId
            ]);

            // 5. 原子锁定账号并执行兑换
            $result = $this->performAtomicExchange($account, $giftCardInfoWithRoom, $rate, $plan);

            return $result;

        } catch (Exception $e) {
            Log::error("礼品卡兑换异常", [
                'card_code' => $giftCardCode,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'EXCHANGE_EXCEPTION',
                'message' => '兑换过程发生异常: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 原子锁定账号并执行兑换
     */
    private function performAtomicExchange(
        ItunesTradeAccount $account,
        array $giftCardInfo,
        ItunesTradeRate $rate,
        ItunesTradePlan $plan
    ): array {
        return DB::transaction(function () use ($account, $giftCardInfo, $rate, $plan) {
            // 1. 再次验证账号状态（防止并发问题）
            $currentAccount = ItunesTradeAccount::lockForUpdate()->find($account->id);
            
            if (!$currentAccount || $currentAccount->status !== ItunesTradeAccount::STATUS_LOCKING) {
                throw new Exception("账号状态已改变，无法继续兑换");
            }

            // 2. 验证总额度限制（最后一次检查）
            $giftCardAmount = $giftCardInfo['amount'];
            $currentBalance = $currentAccount->amount;
            $afterExchangeBalance = $currentBalance + $giftCardAmount;
            
            if ($afterExchangeBalance > $plan->total_amount) {
                // 状态回滚
                $currentAccount->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
                
                throw new Exception("兑换后余额({$afterExchangeBalance})超出计划总额度({$plan->total_amount})");
            }

            // 3. 计算兑换金额
            $exchangedAmount = $giftCardAmount * $rate->rate;
            $newBalance = $currentBalance + $exchangedAmount;

            // 4. 创建兑换记录
            $exchangeRecord = GiftCardExchangeRecord::create([
                'card_code' => $giftCardInfo['code'],
                'card_amount' => $giftCardAmount,
                'card_currency' => $giftCardInfo['currency'] ?? 'USD',
                'country_code' => $giftCardInfo['country_code'],
                'account_id' => $currentAccount->id,
                'plan_id' => $plan->id,
                'rate_id' => $rate->id,
                'room_id' => $giftCardInfo['room_id'],
                'exchanged_amount' => $exchangedAmount,
                'account_balance_before' => $currentBalance,
                'account_balance_after' => $newBalance,
                'status' => 'success',
                'exchange_time' => now(),
                'card_type' => $giftCardInfo['card_type'],
                'card_form' => $giftCardInfo['card_form']
            ]);

            // 5. 更新账号余额和状态
            $currentDay = $currentAccount->current_plan_day ?? 1;
            $updateData = [
                'amount' => $newBalance,
                'status' => ItunesTradeAccount::STATUS_PROCESSING, // 解锁状态
                'last_exchange_time' => now()
            ];

            // 如果账号之前没有绑定计划，需要绑定
            if (!$currentAccount->plan_id) {
                $updateData['plan_id'] = $plan->id;
                $updateData['current_plan_day'] = 1;
                $currentDay = 1;
            }

            // 如果需要绑定群聊
            if ($this->shouldBindRoom($plan, $giftCardInfo['room_id'], $currentAccount)) {
                $updateData['room_id'] = $giftCardInfo['room_id'];
            }

            $currentAccount->update($updateData);

            // 6. 创建账号日志
            ItunesTradeAccountLog::create([
                'account_id' => $currentAccount->id,
                'plan_id' => $plan->id,
                'day' => $currentDay,
                'amount' => $exchangedAmount,
                'before_amount' => $currentBalance,
                'after_amount' => $newBalance,
                'exchange_time' => now(),
                'status' => ItunesTradeAccountLog::STATUS_SUCCESS,
                'gift_card_code' => $giftCardInfo['code'],
                'gift_card_amount' => $giftCardAmount,
                'room_id' => $giftCardInfo['room_id'],
                'rate_id' => $rate->id
            ]);

            // 7. 发送微信通知
            $this->sendWechatNotification($currentAccount, $giftCardInfo, $exchangedAmount, $newBalance, $plan);

            Log::info("兑换成功", [
                'exchange_record_id' => $exchangeRecord->id,
                'account_id' => $currentAccount->id,
                'account_email' => $currentAccount->account,
                'gift_card_amount' => $giftCardAmount,
                'exchanged_amount' => $exchangedAmount,
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
                'plan_id' => $plan->id,
                'room_id' => $giftCardInfo['room_id']
            ]);

            return [
                'success' => true,
                'exchange_record_id' => $exchangeRecord->id,
                'account_id' => $currentAccount->id,
                'account_email' => $currentAccount->account,
                'gift_card_amount' => $giftCardAmount,
                'exchanged_amount' => $exchangedAmount,
                'account_balance_before' => $currentBalance,
                'account_balance_after' => $newBalance,
                'plan_id' => $plan->id,
                'room_id' => $giftCardInfo['room_id'],
                'message' => '兑换成功'
            ];
        });
    }

    /**
     * 验证礼品卡（模拟实现）
     */
    private function validateGiftCard(string $code): array
    {
        // 这里应该调用实际的礼品卡验证API
        Log::info("验证礼品卡", ['code' => $code]);
        
        // 模拟API调用
        $mockCardInfo = [
            'code' => $code,
            'amount' => 300.0, // 从API获取
            'currency' => 'USD',
            'country_code' => 'US', // 从API获取
            'valid' => true,
            'balance' => 300.0
        ];
        
        if (!$mockCardInfo['valid']) {
            throw new Exception('礼品卡无效');
        }
        
        Log::info("礼品卡验证成功", $mockCardInfo);
        return $mockCardInfo;
    }

    /**
     * 查找匹配的汇率
     */
    private function findMatchingRate(array $giftCardInfo, string $cardType, string $cardForm): ItunesTradeRate
    {
        $rate = ItunesTradeRate::where('status', 'active')
            ->where('country_code', $giftCardInfo['country_code'])
            ->where('card_type', $cardType)
            ->where('card_form', $cardForm)
            ->first();

        if (!$rate) {
            throw new Exception("未找到匹配的汇率配置: {$giftCardInfo['country_code']}-{$cardType}-{$cardForm}");
        }

        Log::info("找到匹配汇率", [
            'rate_id' => $rate->id,
            'country' => $giftCardInfo['country_code'],
            'card_type' => $cardType,
            'card_form' => $cardForm,
            'rate' => $rate->rate,
            'amount_constraint' => $rate->amount_constraint
        ]);

        return $rate;
    }

    /**
     * 查找可用计划
     */
    private function findAvailablePlan(int $rateId): ItunesTradePlan
    {
        $plan = ItunesTradePlan::with('rate')
            ->where('rate_id', $rateId)
            ->where('status', 'active')
            ->first();

        if (!$plan) {
            throw new Exception("未找到可用的交易计划: rate_id={$rateId}");
        }

        Log::info("找到可用计划", [
            'plan_id' => $plan->id,
            'rate_id' => $rateId,
            'total_amount' => $plan->total_amount,
            'bind_room' => $plan->bind_room ?? false,
            'plan_days' => $plan->plan_days
        ]);

        return $plan;
    }

    /**
     * 判断是否应该绑定群聊
     */
    private function shouldBindRoom(ItunesTradePlan $plan, ?string $roomId, ItunesTradeAccount $account): bool
    {
        // 如果计划不要求绑定群聊，跳过
        $bindRoom = $plan->bind_room ?? false;
        if (!$bindRoom || empty($roomId)) {
            return false;
        }

        // 如果账号已经绑定了其他群聊，不改变
        if ($account->room_id && $account->room_id !== $roomId) {
            return false;
        }

        return true;
    }

    /**
     * 发送微信通知
     */
    private function sendWechatNotification(
        ItunesTradeAccount $account,
        array $giftCardInfo,
        float $exchangedAmount,
        float $newBalance,
        ItunesTradePlan $plan
    ): void {
        try {
            $roomId = $giftCardInfo['room_id'] ?? '45958721463@chatroom'; // 默认群聊
            
            $message = $this->buildWechatMessage($account, $giftCardInfo, $exchangedAmount, $newBalance, $plan);
            
            // 发送微信消息（使用队列）
            $messageId = send_msg_to_wechat($roomId, $message, 'MT_SEND_TEXTMSG', true, 'gift-card-exchange');
            
            Log::info("微信通知发送成功（队列）", [
                'account_id' => $account->id,
                'room_id' => $roomId,
                'message_length' => strlen($message),
                'message_id' => $messageId
            ]);
            
        } catch (Exception $e) {
            Log::error("微信通知发送失败", [
                'account_id' => $account->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 构建微信消息
     */
    private function buildWechatMessage(
        ItunesTradeAccount $account,
        array $giftCardInfo,
        float $exchangedAmount,
        float $newBalance,
        ItunesTradePlan $plan
    ): string {
        $currentDay = $account->current_plan_day ?? 1;
        $bindRoomStatus = $plan->bind_room ? '是' : '否';
        
        $msg = "[强]礼品卡兑换成功\n";
        $msg .= "---------------------------------\n";
        $msg .= "账号：{$account->account}\n";
        $msg .= "国家：{$account->country_code}   当前第{$currentDay}天\n";
        $msg .= "礼品卡：{$giftCardInfo['amount']} {$giftCardInfo['currency']}\n";
        $msg .= "兑换金额：{$exchangedAmount}\n";
        $msg .= "账户余款：{$newBalance}\n";
        $msg .= "计划总额：{$plan->total_amount}\n";
        $msg .= "群聊绑定：{$bindRoomStatus}\n";
        $msg .= "时间：" . now()->format('Y-m-d H:i:s');

        return $msg;
    }

    /**
     * 批量兑换礼品卡
     */
    public function batchExchangeGiftCards(
        array $giftCards, 
        ?string $roomId = null, 
        string $cardType = '1',
        string $cardForm = 'electronic'
    ): array {
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        Log::info("开始批量兑换礼品卡", [
            'total_cards' => count($giftCards),
            'room_id' => $roomId,
            'card_type' => $cardType,
            'card_form' => $cardForm
        ]);

        foreach ($giftCards as $index => $cardCode) {
            try {
                $result = $this->exchangeGiftCard($cardCode, $roomId, $cardType, $cardForm);
                $results[$index] = $result;
                
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
                
                // 批量兑换时添加短暂延迟，避免并发问题
                usleep(100000); // 100ms
                
            } catch (Exception $e) {
                $failureCount++;
                $results[$index] = [
                    'success' => false,
                    'error' => 'BATCH_EXCHANGE_ERROR',
                    'message' => $e->getMessage(),
                    'card_code' => $cardCode
                ];
                
                Log::error("批量兑换单卡失败", [
                    'index' => $index,
                    'card_code' => $cardCode,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info("批量兑换完成", [
            'total_cards' => count($giftCards),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'success_rate' => round($successCount / count($giftCards) * 100, 2) . '%'
        ]);

        return [
            'total_processed' => count($giftCards),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'success_rate' => round($successCount / count($giftCards) * 100, 2),
            'results' => $results
        ];
    }

    /**
     * 获取账号筛选统计信息
     */
    public function getSelectionStatistics(string $country, ?int $planId = null): array
    {
        $plan = $planId ? ItunesTradePlan::with('rate')->find($planId) : null;
        return $this->findAccountService->getSelectionStatistics($country, $plan);
    }

    /**
     * 获取可用账号数量
     */
    public function getAvailableAccountCount(string $country): int
    {
        return ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->where('country_code', $country)
            ->where('amount', '>=', 0)
            ->count();
    }
} 