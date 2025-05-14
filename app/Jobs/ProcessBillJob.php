<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessBillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 账单记录ID
     */
    protected int $billId;

    /**
     * 失败尝试次数
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 60;

    /**
     * 创建新的任务实例
     */
    public function __construct(int $billId)
    {
        $this->billId = $billId;
    }

    /**
     * 执行任务
     */
    public function handle(): void
    {
        try {
            // 获取账单记录
            $billRecord = DB::table('wechat_bill_records')->where('id', $this->billId)->first();
            
            if (!$billRecord) {
                Log::error("ProcessBillJob: 未找到ID为 {$this->billId} 的账单记录");
                return;
            }
            
            // 检查是否已处理
            if ($billRecord->status !== 0) {
                Log::info("ProcessBillJob: 账单记录 {$this->billId} 已处理，状态: {$billRecord->status}");
                return;
            }
            
            Log::info("ProcessBillJob: 开始处理账单 {$this->billId}, 卡密: {$billRecord->card_code}");
            
            // 执行加账逻辑
            $result = $this->processBill($billRecord);
            
            // 更新状态
            DB::table('wechat_bill_records')
                ->where('id', $this->billId)
                ->update([
                    'status' => $result ? 1 : 2, // 1=成功，2=失败
                    'updated_at' => now(),
                    'processed_at' => now()
                ]);
            
            Log::info("ProcessBillJob: 账单 {$this->billId} 处理" . ($result ? '成功' : '失败'));
            
            // 如果需要，可以在这里发送处理结果通知
            
        } catch (\Exception $e) {
            Log::error("ProcessBillJob: 处理账单 {$this->billId} 时发生错误: " . $e->getMessage());
            
            // 更新为失败状态
            DB::table('wechat_bill_records')
                ->where('id', $this->billId)
                ->update([
                    'status' => 2, // 失败
                    'updated_at' => now(),
                    'error_message' => substr($e->getMessage(), 0, 255) // 保存错误信息
                ]);
            
            // 重新抛出异常，让队列系统处理重试逻辑
            throw $e;
        }
    }
    
    /**
     * 处理账单加账逻辑
     *
     * @param object $billRecord 账单记录
     * @return bool 处理结果
     */
    private function processBill(object $billRecord): bool
    {
        // 根据您的业务需求实现具体的处理逻辑
        // 例如：调用第三方API、更新用户余额等
        
        try {
            // 示例：检查卡密有效性
            $isCardValid = $this->validateCard($billRecord->card_code, $billRecord->card_type);
            
            if (!$isCardValid) {
                Log::warning("ProcessBillJob: 卡密 {$billRecord->card_code} 验证无效");
                return false;
            }
            
            // 示例：更新用户账户余额
            $this->updateUserBalance($billRecord->wxid, $billRecord->amount);
            
            // 记录交易日志
            $this->logTransaction($billRecord);
            
            return true;
        } catch (\Exception $e) {
            Log::error("ProcessBillJob: 处理账单加账失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 验证卡密有效性
     *
     * @param string $cardCode 卡密
     * @param string $cardType 卡类型
     * @return bool 是否有效
     */
    private function validateCard(string $cardCode, string $cardType): bool
    {
        // 实现卡密验证逻辑
        // 这里只是示例，您需要根据实际需求实现
        return !empty($cardCode);
    }
    
    /**
     * 更新用户余额
     *
     * @param string $wxid 微信ID
     * @param float $amount 金额
     */
    private function updateUserBalance(string $wxid, float $amount): void
    {
        // 实现用户余额更新逻辑
        // 这里只是示例，您需要根据实际需求实现
        Log::info("ProcessBillJob: 为用户 {$wxid} 增加余额 {$amount}");
    }
    
    /**
     * 记录交易日志
     *
     * @param object $billRecord 账单记录
     */
    private function logTransaction(object $billRecord): void
    {
        // 实现交易日志记录逻辑
        // 这里只是示例，您需要根据实际需求实现
        DB::table('transaction_logs')->insert([
            'wxid' => $billRecord->wxid,
            'amount' => $billRecord->amount,
            'card_code' => $billRecord->card_code,
            'type' => 'bill_processed',
            'created_at' => now()
        ]);
    }
    
    /**
     * 任务失败时调用
     *
     * @param \Exception $exception
     * @return void
     */
    public function failed(\Exception $exception): void
    {
        // 当任务失败且已达到最大重试次数时执行
        Log::error("ProcessBillJob: 账单 {$this->billId} 处理最终失败: " . $exception->getMessage());
        
        // 更新为最终失败状态
        DB::table('wechat_bill_records')
            ->where('id', $this->billId)
            ->update([
                'status' => 3, // 永久失败
                'updated_at' => now(),
                'error_message' => "最终失败: " . substr($exception->getMessage(), 0, 200)
            ]);
        
        // 可以在这里发送失败通知
    }
} 