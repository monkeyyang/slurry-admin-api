<?php

namespace App\Services;

use App\Models\AccountGroup;
use App\Models\AutoExecutionSetting;
use App\Models\ChargePlan;
use App\Models\ChargePlanItem;
use App\Models\ChargePlanLog;
use App\Models\ChargePlanTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GiftExchangeService
{
    /**
     * Create a new charge plan
     *
     * @param array $data
     * @return ChargePlan
     */
    public function createPlan(array $data)
    {
        try {
            DB::beginTransaction();
            
            $plan = ChargePlan::create([
                'account' => $data['account'],
                'country' => $data['country'],
                'total_amount' => $data['totalAmount'],
                'days' => $data['days'],
                'multiple_base' => $data['multipleBase'],
                'float_amount' => $data['floatAmount'],
                'interval_hours' => $data['intervalHours'],
                'start_time' => $data['startTime'],
                'status' => 'draft',
                'charged_amount' => 0,
                'group_id' => $data['groupId'] ?? null,
                'priority' => $data['priority'] ?? 0,
            ]);
            
            // Generate plan items
            $plan->generateItems();
            
            DB::commit();
            
            return $plan;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create charge plan: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Batch create plans
     *
     * @param array $data
     * @return array
     */
    public function batchCreatePlans(array $data)
    {
        $successCount = 0;
        $failCount = 0;
        $plans = [];
        
        foreach ($data['accounts'] as $account) {
            try {
                $plan = $this->createPlan([
                    'account' => $account,
                    'country' => $data['country'],
                    'totalAmount' => $data['totalAmount'],
                    'days' => $data['days'],
                    'multipleBase' => $data['multipleBase'],
                    'floatAmount' => $data['floatAmount'],
                    'intervalHours' => $data['intervalHours'],
                    'startTime' => $data['startTime'],
                ]);
                
                $successCount++;
                $plans[] = $plan->toApiArray();
            } catch (\Exception $e) {
                Log::error('Failed to create plan for account ' . $account . ': ' . $e->getMessage());
                $failCount++;
            }
        }
        
        return [
            'successCount' => $successCount,
            'failCount' => $failCount,
            'plans' => $plans,
        ];
    }

    /**
     * Update a charge plan
     *
     * @param ChargePlan $plan
     * @param array $data
     * @return ChargePlan
     */
    public function updatePlan(ChargePlan $plan, array $data)
    {
        try {
            DB::beginTransaction();
            
            // Only allow updates for draft plans
            if ($plan->status !== 'draft') {
                throw new \Exception('Only draft plans can be updated');
            }
            
            $plan->update([
                'account' => $data['account'],
                'country' => $data['country'],
                'total_amount' => $data['totalAmount'],
                'days' => $data['days'],
                'multiple_base' => $data['multipleBase'],
                'float_amount' => $data['floatAmount'],
                'interval_hours' => $data['intervalHours'],
                'start_time' => $data['startTime'],
                'group_id' => $data['groupId'] ?? $plan->group_id,
                'priority' => $data['priority'] ?? $plan->priority,
            ]);
            
            // Remove existing items and regenerate
            $plan->items()->delete();
            $plan->generateItems();
            
            DB::commit();
            
            return $plan;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update charge plan: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update plan status
     *
     * @param ChargePlan $plan
     * @param string $status
     * @return ChargePlan
     */
    public function updatePlanStatus(ChargePlan $plan, string $status)
    {
        $validTransitions = [
            'draft' => ['processing', 'cancelled'],
            'processing' => ['paused', 'completed', 'cancelled'],
            'paused' => ['processing', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];
        
        if (!in_array($status, $validTransitions[$plan->status] ?? [])) {
            throw new \Exception("Invalid status transition from {$plan->status} to {$status}");
        }
        
        $plan->status = $status;
        $plan->save();
        
        // Create log entry
        ChargePlanLog::create([
            'plan_id' => $plan->id,
            'time' => Carbon::now()->format('H:i:s'),
            'action' => 'Status changed to ' . $status,
            'status' => 'success',
            'details' => 'Status updated manually',
        ]);
        
        return $plan;
    }

    /**
     * Execute a plan
     *
     * @param ChargePlan $plan
     * @return ChargePlan
     */
    public function executePlan(ChargePlan $plan)
    {
        try {
            // Check if plan can be executed
            if (!in_array($plan->status, ['draft', 'paused'])) {
                throw new \Exception("Plan cannot be executed in current status: {$plan->status}");
            }
            
            // Update plan status
            $plan->status = 'processing';
            $plan->save();
            
            // Create log entry
            ChargePlanLog::create([
                'plan_id' => $plan->id,
                'time' => Carbon::now()->format('H:i:s'),
                'action' => 'Plan execution started',
                'status' => 'success',
                'details' => 'Execution triggered manually',
            ]);
            
            // In a real system, this would trigger a background job for actual execution
            // For now, we'll just simulate starting the execution
            
            return $plan;
        } catch (\Exception $e) {
            Log::error('Failed to execute charge plan: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Pause a plan
     *
     * @param ChargePlan $plan
     * @return ChargePlan
     */
    public function pausePlan(ChargePlan $plan)
    {
        return $this->updatePlanStatus($plan, 'paused');
    }

    /**
     * Resume a plan
     *
     * @param ChargePlan $plan
     * @return ChargePlan
     */
    public function resumePlan(ChargePlan $plan)
    {
        return $this->updatePlanStatus($plan, 'processing');
    }

    /**
     * Cancel a plan
     *
     * @param ChargePlan $plan
     * @return ChargePlan
     */
    public function cancelPlan(ChargePlan $plan)
    {
        return $this->updatePlanStatus($plan, 'cancelled');
    }

    /**
     * Create template from plan
     *
     * @param string $name
     * @param ChargePlan $plan
     * @return ChargePlanTemplate
     */
    public function createTemplateFromPlan(string $name, ChargePlan $plan)
    {
        try {
            return ChargePlanTemplate::createFromPlan($name, $plan);
        } catch (\Exception $e) {
            Log::error('Failed to create template from plan: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create plans from template
     *
     * @param ChargePlanTemplate $template
     * @param array $accounts
     * @param string $startTime
     * @return array
     */
    public function createPlansFromTemplate(ChargePlanTemplate $template, array $accounts, string $startTime)
    {
        $successCount = 0;
        $failCount = 0;
        $plans = [];
        
        foreach ($accounts as $account) {
            try {
                $plan = ChargePlan::create([
                    'account' => $account,
                    'country' => $template->country,
                    'total_amount' => $template->total_amount,
                    'days' => $template->days,
                    'multiple_base' => $template->multiple_base,
                    'float_amount' => $template->float_amount,
                    'interval_hours' => $template->interval_hours,
                    'start_time' => $startTime,
                    'status' => 'draft',
                    'charged_amount' => 0,
                ]);
                
                // Create items based on template items
                foreach ($template->items as $itemData) {
                    ChargePlanItem::create([
                        'plan_id' => $plan->id,
                        'day' => $itemData['day'],
                        'time' => $itemData['time'],
                        'amount' => $itemData['amount'],
                        'min_amount' => $itemData['minAmount'],
                        'max_amount' => $itemData['maxAmount'],
                        'description' => $itemData['description'] ?? "Day {$itemData['day']} charge",
                        'status' => 'pending',
                    ]);
                }
                
                $successCount++;
                $plans[] = $plan->toApiArray();
            } catch (\Exception $e) {
                Log::error('Failed to create plan from template for account ' . $account . ': ' . $e->getMessage());
                $failCount++;
            }
        }
        
        return [
            'successCount' => $successCount,
            'failCount' => $failCount,
            'plans' => $plans,
        ];
    }

    /**
     * Create account group
     *
     * @param array $data
     * @return AccountGroup
     */
    public function createAccountGroup(array $data)
    {
        try {
            $group = AccountGroup::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'country' => $data['country'],
                'total_target_amount' => $data['totalTargetAmount'] ?? null,
                'current_amount' => 0,
                'status' => 'active',
                'auto_switch' => $data['autoSwitch'] ?? false,
                'switch_threshold' => $data['switchThreshold'] ?? null,
            ]);
            
            return $group;
        } catch (\Exception $e) {
            Log::error('Failed to create account group: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Add plans to group
     *
     * @param AccountGroup $group
     * @param array $planIds
     * @return AccountGroup
     */
    public function addPlansToGroup(AccountGroup $group, array $planIds)
    {
        try {
            DB::beginTransaction();
            
            // Update all plans
            ChargePlan::whereIn('id', $planIds)
                ->where('status', '!=', 'completed')
                ->where('status', '!=', 'cancelled')
                ->update(['group_id' => $group->id]);
            
            // Update account count
            $group->updateAccountCount();
            
            DB::commit();
            
            return $group;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add plans to group: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Remove plans from group
     *
     * @param AccountGroup $group
     * @param array $planIds
     * @return AccountGroup
     */
    public function removePlansFromGroup(AccountGroup $group, array $planIds)
    {
        try {
            DB::beginTransaction();
            
            // Update all plans
            ChargePlan::whereIn('id', $planIds)
                ->where('group_id', $group->id)
                ->update(['group_id' => null]);
            
            // Update account count
            $group->updateAccountCount();
            
            DB::commit();
            
            return $group;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to remove plans from group: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update plan priorities
     *
     * @param AccountGroup $group
     * @param array $planPriorities
     * @return AccountGroup
     */
    public function updatePlanPriorities(AccountGroup $group, array $planPriorities)
    {
        try {
            DB::beginTransaction();
            
            foreach ($planPriorities as $planPriority) {
                ChargePlan::where('id', $planPriority['planId'])
                    ->where('group_id', $group->id)
                    ->update(['priority' => $planPriority['priority']]);
            }
            
            DB::commit();
            
            return $group;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update plan priorities: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Start account group
     *
     * @param AccountGroup $group
     * @return AccountGroup
     */
    public function startAccountGroup(AccountGroup $group)
    {
        try {
            DB::beginTransaction();
            
            $group->status = 'active';
            $group->save();
            
            // Update all plans in the group
            ChargePlan::where('group_id', $group->id)
                ->whereIn('status', ['draft', 'paused'])
                ->update(['status' => 'processing']);
            
            DB::commit();
            
            return $group;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to start account group: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Pause account group
     *
     * @param AccountGroup $group
     * @return AccountGroup
     */
    public function pauseAccountGroup(AccountGroup $group)
    {
        try {
            DB::beginTransaction();
            
            $group->status = 'paused';
            $group->save();
            
            // Update all processing plans in the group
            ChargePlan::where('group_id', $group->id)
                ->where('status', 'processing')
                ->update(['status' => 'paused']);
            
            DB::commit();
            
            return $group;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to pause account group: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get auto execution status
     *
     * @return array
     */
    public function getAutoExecutionStatus()
    {
        $settings = AutoExecutionSetting::getSettings();
        
        $activeGroups = AccountGroup::where('status', 'active')->count();
        $activePlans = ChargePlan::where('status', 'processing')->count();
        
        return [
            'isRunning' => $settings->enabled,
            'activeGroups' => $activeGroups,
            'activePlans' => $activePlans,
            'lastExecutionTime' => $settings->last_execution_time ? $settings->last_execution_time->toISOString() : null,
            'nextExecutionTime' => $settings->next_execution_time ? $settings->next_execution_time->toISOString() : null,
        ];
    }

    /**
     * Update auto execution settings
     *
     * @param array $data
     * @return AutoExecutionSetting
     */
    public function updateAutoExecutionSettings(array $data)
    {
        try {
            $settings = AutoExecutionSetting::getSettings();
            
            $settings->update([
                'enabled' => $data['enabled'],
                'execution_interval' => $data['executionInterval'],
                'max_concurrent_plans' => $data['maxConcurrentPlans'],
                'log_level' => $data['logLevel'],
            ]);
            
            if ($settings->enabled) {
                $settings->next_execution_time = now()->addMinutes($settings->execution_interval);
                $settings->save();
            }
            
            return $settings;
        } catch (\Exception $e) {
            Log::error('Failed to update auto execution settings: ' . $e->getMessage());
            throw $e;
        }
    }
}