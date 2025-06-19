<?php

namespace App\Console\Commands;

use App\Models\ChargePlan;
use App\Models\ChargePlanItem;
use App\Models\ChargePlanLog;
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

        // 获取所有处理中的计划
        $plans = ChargePlan::where('status', 'processing')->get();

        $updatedCount = 0;

        foreach ($plans as $plan) {
            try {
                if ($this->checkAndUpdatePlanProgress($plan)) {
                    $updatedCount++;
                }
            } catch (\Exception $e) {
                $this->error("处理计划 {$plan->id} 时发生错误: " . $e->getMessage());
                Log::channel('gift_card_exchange')->error("CheckPlanDayProgressCommand: 处理计划 {$plan->id} 失败", [
                    'error' => $e->getMessage(),
                    'plan_id' => $plan->id
                ]);
            }
        }

        $this->info("检查完成，共更新了 {$updatedCount} 个计划的天数进度");
    }

    /**
     * 检查并更新单个计划的进度
     *
     * @param ChargePlan $plan
     * @return bool 是否有更新
     */
    private function checkAndUpdatePlanProgress(ChargePlan $plan): bool
    {
        $currentDay = $plan->current_day ?? 1;
        $updated = false;

        // 获取当前天的计划项
        $currentDayItem = $plan->items()
            ->where('day', $currentDay)
            ->first();

        if (!$currentDayItem) {
            return false;
        }

        // 如果当前天已完成，检查是否可以进入下一天
        if ($currentDayItem->status === 'completed') {
            $nextDay = $currentDay + 1;
            
            // 检查是否还有下一天的计划
            $nextDayItem = $plan->items()
                ->where('day', $nextDay)
                ->first();

            if ($nextDayItem && $nextDayItem->status === 'pending') {
                // 获取当前天最后一次成功执行的时间
                $lastExecutionTime = ChargePlanLog::where('plan_id', $plan->id)
                    ->where('day', $currentDay)
                    ->where('status', 'success')
                    ->where('action', 'gift_card_exchange')
                    ->latest('created_at')
                    ->value('created_at');

                if ($lastExecutionTime) {
                    $lastExecution = Carbon::parse($lastExecutionTime);
                    $now = Carbon::now();

                    // 检查是否已过去24小时
                    if ($now->diffInHours($lastExecution) >= 24) {
                        // 可以进入下一天
                        $plan->current_day = $nextDay;
                        $plan->save();

                        $this->info("计划 {$plan->id} (账号: {$plan->account}) 从第 {$currentDay} 天进入第 {$nextDay} 天");
                        
                        Log::channel('gift_card_exchange')->info("定时任务: 计划 {$plan->id} 从第 {$currentDay} 天进入第 {$nextDay} 天");
                        
                        $updated = true;
                    }
                }
            }
        }

        return $updated;
    }
} 