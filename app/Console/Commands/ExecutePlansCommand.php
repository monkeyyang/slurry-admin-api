<?php

namespace App\Console\Commands;

use App\Models\AutoExecutionSetting;
use App\Models\ChargePlan;
use App\Models\ChargePlanLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExecutePlansCommand extends Command
{
    protected $signature = 'plans:execute {--force : Force execution regardless of settings}';
    protected $description = 'Execute pending charge plans';

    public function handle()
    {
        $settings = AutoExecutionSetting::getSettings();
        
        if (!$settings->enabled && !$this->option('force')) {
            $this->info('Auto execution is disabled. Use --force to run anyway.');
            return 0;
        }
        
        $this->info('Starting plan execution...');
        
        try {
            DB::beginTransaction();
            
            // Update execution times
            $settings->updateExecutionTimes();
            
            // Find active plans
            $plans = ChargePlan::where('status', 'processing')
                ->orderBy('priority', 'desc')
                ->limit($settings->max_concurrent_plans)
                ->get();
            
            $this->info("Found {$plans->count()} plans to process");
            
            foreach ($plans as $plan) {
                $this->processPlan($plan);
            }
            
            DB::commit();
            $this->info('Execution completed successfully');
            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Plan execution failed: ' . $e->getMessage());
            $this->error('Execution failed: ' . $e->getMessage());
            return 1;
        }
    }
    
    protected function processPlan(ChargePlan $plan)
    {
        $this->info("Processing plan ID {$plan->id} for account {$plan->account}");
        
        // Get current day items
        $currentDay = $plan->current_day ?? 1;
        $this->info("Current day: {$currentDay}");
        
        $items = $plan->items()
            ->where('day', $currentDay)
            ->where('status', 'pending')
            ->get();
        
        if ($items->isEmpty()) {
            $this->info("No pending items for day {$currentDay}");
            
            // Check if we should advance to next day
            $nextDay = $currentDay + 1;
            if ($nextDay <= $plan->days) {
                $this->info("Advancing to day {$nextDay}");
                $plan->current_day = $nextDay;
                $plan->save();
            } else {
                $this->info("Plan completed");
                $plan->status = 'completed';
                $plan->save();
                
                // Log completion
                ChargePlanLog::create([
                    'plan_id' => $plan->id,
                    'time' => Carbon::now()->format('H:i:s'),
                    'action' => 'Plan completed',
                    'status' => 'success',
                    'details' => 'All days processed',
                ]);
            }
            
            return;
        }
        
        // Process items
        foreach ($items as $item) {
            $this->info("Processing item ID {$item->id} for day {$item->day}");
            
            try {
                // Check if it's time to execute this item
                $itemTime = Carbon::parse($item->time);
                $now = Carbon::now();
                $startDate = Carbon::parse($plan->start_time);
                
                $executionDate = $startDate->copy()->addDays($item->day - 1);
                $executionDateTime = Carbon::create(
                    $executionDate->year,
                    $executionDate->month,
                    $executionDate->day,
                    $itemTime->hour,
                    $itemTime->minute,
                    $itemTime->second
                );
                
                if ($now->lt($executionDateTime)) {
                    $this->info("Item not due yet. Scheduled for {$executionDateTime->format('Y-m-d H:i:s')}");
                    continue;
                }
                
                // Mark as processing
                $item->status = 'processing';
                $item->save();
                
                // In a real system, this would make an external API call to execute the charge
                // For simulation, we'll just mark it as completed
                $simulatedResult = "Simulated charge execution for {$item->amount}";
                
                // Mark as completed
                $item->status = 'completed';
                $item->executed_at = now();
                $item->result = $simulatedResult;
                $item->save();
                
                // Update plan charged amount
                $plan->charged_amount = ($plan->charged_amount ?? 0) + $item->amount;
                $plan->save();
                
                // Create log entry
                ChargePlanLog::create([
                    'plan_id' => $plan->id,
                    'item_id' => $item->id,
                    'day' => $item->day,
                    'time' => Carbon::now()->format('H:i:s'),
                    'action' => 'Item executed',
                    'status' => 'success',
                    'details' => $simulatedResult,
                ]);
                
                $this->info("Item execution completed");
                
                // If the plan is part of a group, update group amount
                if ($plan->group_id) {
                    $group = $plan->group;
                    if ($group) {
                        $group->incrementAmount($item->amount);
                        $this->info("Updated group {$group->name} amount");
                    }
                }
            } catch (\Exception $e) {
                $this->error("Item execution failed: " . $e->getMessage());
                
                // Mark as failed
                $item->status = 'failed';
                $item->result = "Error: " . $e->getMessage();
                $item->save();
                
                // Create log entry
                ChargePlanLog::create([
                    'plan_id' => $plan->id,
                    'item_id' => $item->id,
                    'day' => $item->day,
                    'time' => Carbon::now()->format('H:i:s'),
                    'action' => 'Item execution failed',
                    'status' => 'failed',
                    'details' => $e->getMessage(),
                ]);
            }
        }
        
        // Check if all items for the current day are completed
        $pendingItems = $plan->items()->where('day', $currentDay)->where('status', 'pending')->count();
        $processingItems = $plan->items()->where('day', $currentDay)->where('status', 'processing')->count();
        
        if ($pendingItems == 0 && $processingItems == 0) {
            $this->info("All items for day {$currentDay} completed");
            
            // Advance to next day
            $nextDay = $currentDay + 1;
            if ($nextDay <= $plan->days) {
                $this->info("Advancing to day {$nextDay}");
                $plan->current_day = $nextDay;
                $plan->save();
            } else {
                $this->info("Plan completed");
                $plan->status = 'completed';
                $plan->save();
                
                // Log completion
                ChargePlanLog::create([
                    'plan_id' => $plan->id,
                    'time' => Carbon::now()->format('H:i:s'),
                    'action' => 'Plan completed',
                    'status' => 'success',
                    'details' => 'All days processed',
                ]);
            }
        }
    }
} 