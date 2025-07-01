<?php

namespace App\Services\Gift;

use App\Services\AccountPoolService;
use App\Models\GiftCardExchangeRecord;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * 基于账号池的礼品卡兑换服务
 * 
 * 功能：
 * 1. 使用Redis账号池快速获取可用账号
 * 2. 支持按国家、金额、计划、群聊精确匹配
 * 3. 自动处理账号锁定和释放
 * 4. 零间隔兑换支持
 */
class PoolBasedGiftCardService
{
    private AccountPoolService $poolService;

    public function __construct(AccountPoolService $poolService)
    {
        $this->poolService = $poolService;
    }

    /**
     * 兑换礼品卡
     * 
     * @param array $giftCard 礼品卡信息
     * @param int|null $planId 计划ID
     * @param int|null $roomId 群聊ID
     * @param float $exchangeRate 汇率
     * @return array 兑换结果
     */
    public function exchangeGiftCard(array $giftCard, ?int $planId = null, ?int $roomId = null, float $exchangeRate = 1.0): array
    {
        $country = $giftCard['country'] ?? 'US';
        $amount = $giftCard['amount'] ?? 0;
        $cardCode = $giftCard['code'] ?? '';

        Log::info("开始礼品卡兑换", [
            'card_amount' => $amount,
            'country' => $country,
            'plan_id' => $planId,
            'room_id' => $roomId,
            'exchange_rate' => $exchangeRate
        ]);

        // 从账号池获取可用账号
        $accountData = $this->poolService->getAvailableAccount(
            $country, 
            $amount, 
            $planId, 
            $roomId, 
            $exchangeRate
        );

        if (!$accountData) {
            Log::warning("没有可用账号进行兑换", [
                'country' => $country,
                'amount' => $amount,
                'plan_id' => $planId,
                'room_id' => $roomId
            ]);

            return [
                'success' => false,
                'error' => 'NO_AVAILABLE_ACCOUNT',
                'message' => '没有可用的账号进行兑换'
            ];
        }

        try {
            // 执行兑换逻辑
            $result = $this->performExchange($accountData, $giftCard, $exchangeRate);

            // 释放账号锁
            $this->poolService->releaseAccountLock($accountData['lock_key']);

            // 更新账号池中的余额
            if ($result['success']) {
                $newBalance = $result['account_balance_after'];
                $this->poolService->updateAccountBalance($accountData['id'], $newBalance);
                
                Log::info("兑换成功，更新账号池余额", [
                    'account_id' => $accountData['id'],
                    'new_balance' => $newBalance
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            // 发生异常，释放账号锁
            $this->poolService->releaseAccountLock($accountData['lock_key']);
            
            Log::error("礼品卡兑换异常", [
                'account_id' => $accountData['id'],
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
     * 批量兑换礼品卡
     * 
     * @param array $giftCards 礼品卡列表
     * @param int|null $planId 计划ID
     * @param int|null $roomId 群聊ID
     * @param float $exchangeRate 汇率
     * @return array 批量兑换结果
     */
    public function batchExchangeGiftCards(array $giftCards, ?int $planId = null, ?int $roomId = null, float $exchangeRate = 1.0): array
    {
        $results = [
            'total' => count($giftCards),
            'success' => 0,
            'failed' => 0,
            'details' => []
        ];

        Log::info("开始批量礼品卡兑换", [
            'total_cards' => $results['total'],
            'plan_id' => $planId,
            'room_id' => $roomId,
            'exchange_rate' => $exchangeRate
        ]);

        foreach ($giftCards as $index => $giftCard) {
            $result = $this->exchangeGiftCard($giftCard, $planId, $roomId, $exchangeRate);
            
            $results['details'][$index] = $result;
            
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }

            // 避免过快的连续请求
            if ($index < count($giftCards) - 1) {
                usleep(100000); // 100ms延迟
            }
        }

        Log::info("批量兑换完成", [
            'total' => $results['total'],
            'success' => $results['success'],
            'failed' => $results['failed']
        ]);

        return $results;
    }

    /**
     * 执行实际的兑换操作
     */
    private function performExchange(array $accountData, array $giftCard, float $exchangeRate): array
    {
        $accountId = $accountData['id'];
        $account = ItunesTradeAccount::find($accountId);
        
        if (!$account) {
            throw new \Exception("账号不存在: {$accountId}");
        }

        $country = $giftCard['country'] ?? 'US';
        $amount = $giftCard['amount'] ?? 0;
        $cardCode = $giftCard['code'] ?? '';
        
        // 计算兑换后余额
        $exchangedAmount = $amount / $exchangeRate;
        $oldBalance = $account->amount;
        $newBalance = $oldBalance + $exchangedAmount;

        Log::info("执行兑换操作", [
            'account_id' => $accountId,
            'account_email' => $account->account,
            'card_amount' => $amount,
            'exchange_rate' => $exchangeRate,
            'exchanged_amount' => $exchangedAmount,
            'old_balance' => $oldBalance,
            'new_balance' => $newBalance
        ]);

        // 在数据库事务中执行
        return DB::transaction(function () use ($account, $giftCard, $exchangedAmount, $newBalance, $oldBalance) {
            // 更新账号余额
            $account->update(['amount' => $newBalance]);

            // 创建兑换记录
            $exchangeRecord = GiftCardExchangeRecord::create([
                'account_id' => $account->id,
                'card_code' => $giftCard['code'] ?? '',
                'card_amount' => $giftCard['amount'] ?? 0,
                'exchanged_amount' => $exchangedAmount,
                'exchange_rate' => 1.0, // 这里可以根据实际需要调整
                'before_amount' => $oldBalance,
                'after_amount' => $newBalance,
                'status' => 'success',
                'exchange_time' => now(),
                'country_code' => $giftCard['country'] ?? 'US'
            ]);

            // 如果有计划，创建账号日志
            if ($account->plan_id && $account->current_plan_day) {
                ItunesTradeAccountLog::create([
                    'account_id' => $account->id,
                    'day' => $account->current_plan_day,
                    'amount' => $exchangedAmount,
                    'before_amount' => $oldBalance,
                    'after_amount' => $newBalance,
                    'status' => ItunesTradeAccountLog::STATUS_SUCCESS,
                    'exchange_time' => now(),
                    'card_code' => $giftCard['code'] ?? '',
                    'note' => '池子兑换成功'
                ]);
            }

            Log::info("兑换成功", [
                'exchange_record_id' => $exchangeRecord->id,
                'account_id' => $account->id,
                'exchanged_amount' => $exchangedAmount,
                'new_balance' => $newBalance
            ]);

            return [
                'success' => true,
                'exchange_record_id' => $exchangeRecord->id,
                'account_id' => $account->id,
                'account_email' => $account->account,
                'exchanged_amount' => $exchangedAmount,
                'account_balance_before' => $oldBalance,
                'account_balance_after' => $newBalance,
                'message' => '兑换成功'
            ];
        });
    }

    /**
     * 获取账号池统计信息
     */
    public function getPoolStatistics(): array
    {
        return $this->poolService->getPoolStats();
    }

    /**
     * 刷新指定条件的账号池
     */
    public function refreshPoolForConditions(string $country, float $amount, ?int $planId = null, ?int $roomId = null): void
    {
        $this->poolService->refreshPool($country, $amount, $planId, $roomId);
    }

    /**
     * 预热账号池（为常见的兑换场景预先建立池子）
     */
    public function warmupPools(): void
    {
        Log::info("开始预热账号池");

        // 常见国家和金额组合
        $combinations = [
            ['country' => 'US', 'amounts' => [25, 50, 100, 200, 500]],
            ['country' => 'CA', 'amounts' => [25, 50, 100, 200, 500]],
            ['country' => 'AU', 'amounts' => [30, 50, 100, 200, 500]],
            ['country' => 'GB', 'amounts' => [25, 50, 100, 200, 500]],
        ];

        foreach ($combinations as $combo) {
            foreach ($combo['amounts'] as $amount) {
                try {
                    // 刷新通用池
                    $this->poolService->refreshPool($combo['country'], $amount);
                    
                    // 可以根据需要添加特定计划的池子
                    // $this->poolService->refreshPool($combo['country'], $amount, $specificPlanId);
                    
                } catch (\Exception $e) {
                    Log::warning("预热池子失败", [
                        'country' => $combo['country'],
                        'amount' => $amount,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        Log::info("账号池预热完成");
    }
} 