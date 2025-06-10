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
use Illuminate\Support\Facades\Crypt;

/**
 * Gift Exchange Service
 * 
 * 礼品兑换服务，处理充值计划的创建、管理和执行
 * 
 * 新增功能：
 * 1. 账号去重：创建计划时自动检查账号是否已存在于活跃计划中
 * 2. 密码加密：使用Laravel对称加密存储密码，确保数据安全
 * 3. API链接解析：支持解析账号字符串中的验证URL链接
 * 
 * 账号字符串格式支持：
 * - "account password"
 * - "account password http://verify.example.com"  
 * - "account password https://verify.example.com"
 * - "account\tpassword\thttp://verify.example.com" (制表符分隔)
 * 
 * 使用示例：
 * ```php
 * $service = new GiftExchangeService();
 * 
 * // 创建计划（自动去重和加密）
 * $plan = $service->createPlan([
 *     'account' => 'user@example.com password123 https://verify.example.com',
 *     'country' => 'US',
 *     'totalAmount' => 100.00,
 *     // ... 其他参数
 * ]);
 * 
 * // 获取解密后的账号信息
 * $accountInfo = $service->getDecryptedAccountInfo($plan);
 * // $accountInfo = ['account' => 'user@example.com', 'password' => 'password123', 'verify_url' => 'https://verify.example.com']
 * ```
 */
class GiftExchangeService
{
    protected $wechatRoomBindingService;

    public function __construct(WechatRoomBindingService $wechatRoomBindingService = null)
    {
        $this->wechatRoomBindingService = $wechatRoomBindingService;
    }

    /**
     * Parse account, password and api from account string
     *
     * @param string $accountString
     * @return array
     */
    protected function parseAccountAndPassword(string $accountString): array
    {
        // 账号、密码和API可能以空格、制表符或其他分隔符连接
        // 例如: 
        // "gordony1982@icloud.com\tzIxHkNvAV0" 
        // "gordony1982@icloud.com zIxHkNvAV0"
        // "gordony1982@icloud.com zIxHkNvAV0 http://api.example.com"
        // "gordony1982@icloud.com zIxHkNvAV0    https://api.example.com"

        // 使用正则表达式来解析账号、密码和可选的API链接
        // 匹配模式：账号 + 空白字符 + 密码 + 可选的(空白字符 + API链接)
        $pattern = '/^([^\s\t]+)[\s\t]+([^\s\t]+)(?:[\s\t]+(https?:\/\/[^\s\t]+))?/';
        
        if (preg_match($pattern, trim($accountString), $matches)) {
            return [
                'account' => trim($matches[1]),
                'password' => trim($matches[2]),
                'verify_url' => isset($matches[3]) ? trim($matches[3]) : null
            ];
        }

        // 如果正则匹配失败，尝试传统的分隔符方式
        $separators = ['\t', ' ', '|', ','];
        
        foreach ($separators as $separator) {
            if ($separator === '\t') {
                $parts = explode("\t", $accountString);
            } else {
                $parts = explode($separator, $accountString);
            }

            if (count($parts) >= 2) {
                $result = [
                    'account' => trim($parts[0]),
                    'password' => trim($parts[1]),
                    'verify_url' => null
                ];
                
                // 检查是否有第三部分且是API链接
                if (count($parts) >= 3) {
                    $potentialApi = trim($parts[2]);
                    if (preg_match('/^https?:\/\//', $potentialApi)) {
                        $result['verify_url'] = $potentialApi;
                    }
                }
                
                return $result;
            }
        }

        // 如果没有找到分隔符，返回原始字符串作为账号，密码和API为空
        return [
            'account' => trim($accountString),
            'password' => '',
            'verify_url' => null
        ];
    }

    /**
     * Encrypt password using Laravel's encryption
     *
     * @param string $password
     * @return string
     */
    protected function encryptPassword(string $password): string
    {
        if (empty($password)) {
            return '';
        }
        
        try {
            return Crypt::encryptString($password);
        } catch (\Exception $e) {
            Log::error('Failed to encrypt password: ' . $e->getMessage());
            throw new \Exception('Password encryption failed');
        }
    }

    /**
     * Decrypt password using Laravel's decryption
     *
     * @param string $encryptedPassword
     * @return string
     */
    public function decryptPassword(string $encryptedPassword): string
    {
        if (empty($encryptedPassword)) {
            return '';
        }
        
        try {
            return Crypt::decryptString($encryptedPassword);
        } catch (\Exception $e) {
            Log::error('Failed to decrypt password: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Check if account already exists in active plans
     *
     * @param string $account
     * @param int|null $excludePlanId
     * @return bool
     */
    protected function isAccountDuplicate(string $account, int $excludePlanId = null): bool
    {
        $query = ChargePlan::where('account', $account)
            ->whereNotIn('status', ['completed', 'cancelled']);
            
        if ($excludePlanId) {
            $query->where('id', '!=', $excludePlanId);
        }
        
        return $query->exists();
    }

    /**
     * Get decrypted account information from a plan
     *
     * @param ChargePlan $plan
     * @return array
     */
    public function getDecryptedAccountInfo(ChargePlan $plan): array
    {
        return [
            'account' => $plan->account,
            'password' => $this->decryptPassword($plan->password),
            'verify_url' => $plan->verify_url,
        ];
    }

    /**
     * Create a new charge plan
     *
     * @param array $data
     * @return ChargePlan
     */
    public function createPlan(array $data): ChargePlan
    {
        try {
            DB::beginTransaction();

            // 解析账号、密码和API
            $accountData = $this->parseAccountAndPassword($data['account']);
            
            // 检查账号是否重复
            if ($this->isAccountDuplicate($accountData['account'])) {
                throw new \Exception("Account '{$accountData['account']}' already exists in active plans");
            }

            // 加密密码
            $encryptedPassword = $this->encryptPassword($accountData['password']);

            $plan = ChargePlan::create([
                'account' => $accountData['account'],
                'password' => $encryptedPassword,
                'verify_url' => $accountData['verify_url'],
                'country' => $data['country'],
                'total_amount' => $data['totalAmount'],
                'days' => $data['days'],
                'multiple_base' => $data['multipleBase'],
                'float_amount' => $data['floatAmount'],
                'interval_hours' => $data['intervalHours'],
                'start_time' => $data['startTime'],
                'status' => $data['status'] ?? 'processing',
                'charged_amount' => 0,
                'group_id' => $data['groupId'] ?? null,
                'priority' => $data['priority'] ?? 0,
            ]);

            // Create plan items - use custom items if provided, otherwise generate automatically
            $plan->generateItems();
//            if (is_array($data['items']) && !empty($data['items'])) {
//                $this->createCustomItems($plan, $data['items']);
//            } else {
//                $plan->generateItems();
//            }

            DB::commit();

            return $plan;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create charge plan: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create custom plan items from provided data
     *
     * @param ChargePlan $plan
     * @param array $items
     * @return void
     */
    protected function createCustomItems(ChargePlan $plan, array $items)
    {
        foreach ($items as $itemData) {
            // Parse time from the provided time string
            $timeString = $itemData['time'] ?? '00:00:00';

            // If time contains date, extract only the time part
            if (strpos($timeString, ' ') !== false) {
                $timeParts = explode(' ', $timeString);
                $timeString = end($timeParts); // Get the last part (time)
            }

            // If time is still in datetime format, parse it
            if (strlen($timeString) > 8) {
                try {
                    $timeString = Carbon::parse($timeString)->format('H:i:s');
                } catch (\Exception $e) {
                    $timeString = '00:00:00'; // Fallback
                }
            }

            ChargePlanItem::create([
                'plan_id' => $plan->id,
                'day' => $itemData['day'],
                'time' => $timeString,
                'amount' => $itemData['amount'],
                'min_amount' => $itemData['minAmount'] ?? $itemData['amount'],
                'max_amount' => $itemData['maxAmount'] ?? $itemData['amount'],
                'description' => $itemData['description'] ?? "Day {$itemData['day']} charge",
                'status' => 'pending',
            ]);
        }
    }

    /**
     * Batch create plans
     *
     * @param array $data
     * @return array
     */
    public function batchCreatePlans(array $data): array
    {
        $successCount = 0;
        $failCount = 0;
        $plans = [];
        $duplicateAccounts = [];

        foreach ($data['accounts'] as $account) {
            try {
                // 解析账号信息
                $accountData = $this->parseAccountAndPassword($account);
                
                // 检查是否重复
                if ($this->isAccountDuplicate($accountData['account'])) {
                    $duplicateAccounts[] = $accountData['account'];
                    $failCount++;
                    continue;
                }

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
            'duplicateAccounts' => $duplicateAccounts,
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

            // 解析账号、密码和API
            $accountData = $this->parseAccountAndPassword($data['account']);
            
            // 检查账号是否重复（排除当前计划）
            if ($this->isAccountDuplicate($accountData['account'], $plan->id)) {
                throw new \Exception("Account '{$accountData['account']}' already exists in other active plans");
            }

            // 加密密码
            $encryptedPassword = $this->encryptPassword($accountData['password']);

            $plan->update([
                'account' => $accountData['account'],
                'password' => $encryptedPassword,
                'verify_url' => $accountData['verify_url'],
                'country' => $data['country'],
                'total_amount' => $data['totalAmount'],
                'days' => $data['days'],
                'multiple_base' => $data['multipleBase'],
                'float_amount' => $data['floatAmount'],
                'interval_hours' => $data['intervalHours'],
                'start_time' => $data['startTime'],
                'status' => $data['status'] ?? $plan->status,
                'group_id' => $data['groupId'] ?? $plan->group_id,
                'priority' => $data['priority'] ?? $plan->priority,
            ]);

            // Remove existing items and create new ones
            $plan->items()->delete();

            // Create plan items - use custom items if provided, otherwise generate automatically
            if (isset($data['items']) && is_array($data['items']) && !empty($data['items'])) {
                $this->createCustomItems($plan, $data['items']);
            } else {
                $plan->generateItems();
            }

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
     * Create template from plan data
     *
     * @param string $name
     * @param array $planData
     * @return ChargePlanTemplate
     */
    public function createTemplateFromData(string $name, array $planData)
    {
        try {
            return ChargePlanTemplate::create([
                'name' => $name,
                'country' => $planData['country'] ?? '',
                'total_amount' => $planData['totalAmount'] ?? 0,
                'days' => $planData['days'] ?? 1,
                'multiple_base' => $planData['multipleBase'] ?? 0,
                'float_amount' => $planData['floatAmount'] ?? 0,
                'interval_hours' => $planData['intervalHours'] ?? 24,
                'items' => $planData['items'] ?? [],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create template from data: ' . $e->getMessage());
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
        $duplicateAccounts = [];

        foreach ($accounts as $account) {
            try {
                // 解析账号、密码和API
                $accountData = $this->parseAccountAndPassword($account);
                
                // 检查是否重复
                if ($this->isAccountDuplicate($accountData['account'])) {
                    $duplicateAccounts[] = $accountData['account'];
                    $failCount++;
                    continue;
                }

                // 加密密码
                $encryptedPassword = $this->encryptPassword($accountData['password']);

                $plan = ChargePlan::create([
                    'account' => $accountData['account'],
                    'password' => $encryptedPassword,
                    'verify_url' => $accountData['verify_url'],
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
            'duplicateAccounts' => $duplicateAccounts,
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
