<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountGroup;
use App\Models\ChargePlan;
use App\Models\ChargePlanTemplate;
use App\Services\GiftExchangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GiftExchangeController extends Controller
{
    protected GiftExchangeService $giftExchangeService;

    public function __construct(GiftExchangeService $giftExchangeService)
    {
        $this->giftExchangeService = $giftExchangeService;
    }

    /**
     * 保存充值计划
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function savePlan(Request $request): JsonResponse
    {
        try {
            $plan = $this->giftExchangeService->createPlan($request->all());
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $plan->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save charge plan: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 批量导入账号并创建充值计划
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchCreatePlans(Request $request): JsonResponse
    {
        try {
            $result = $this->giftExchangeService->batchCreatePlans($request->all());
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to batch create plans: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取充值计划列表
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPlans(Request $request): JsonResponse
    {
        try {
            $query = ChargePlan::query();

            // Apply filters
            if ($request->has('account')) {
                $query->where('account', 'like', '%' . $request->input('account') . '%');
            }

            if ($request->has('country')) {
                $query->where('country', $request->input('country'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->has('groupId')) {
                $query->where('group_id', $request->input('groupId'));
            }

            // Apply sorting
            $sortField = $request->input('sortField', 'updated_at');
            $sortOrder = $request->input('sortOrder', 'desc');

            // Valid sort fields
            $validSortFields = ['account', 'country', 'total_amount', 'days', 'status', 'updated_at', 'start_time'];
            if (in_array($sortField, $validSortFields)) {
                $query->orderBy($sortField, $sortOrder === 'desc' ? 'desc' : 'asc');
            }

            // Apply pagination
            $page = $request->input('page', 1);
            $pageSize = $request->input('pageSize', 10);

            // 加载关联数据
            $query->with(['items', 'wechatRoomBinding']);

            $plans = $query->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'list' => $plans->items() ? collect($plans->items())->map(function($plan) {
                        return $plan->toApiArray();
                    }) : [],
                    'total' => $plans->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get plans: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取单个充值计划
     *
     * @param string $id
     * @return JsonResponse
     */
    public function getPlan(string $id): JsonResponse
    {
        try {
            $plan = ChargePlan::with('items')->findOrFail($id);
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $plan->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get charge plan: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 更新充值计划
     *
     * @param string $id
     * @param Request $request
     * @return JsonResponse
     */
    public function updatePlan(string $id, Request $request): JsonResponse
    {
        try {
            $plan = ChargePlan::findOrFail($id);
            $updatedPlan = $this->giftExchangeService->updatePlan($plan, $request->all());
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedPlan->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update charge plan: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 删除充值计划
     *
     * @param string $id
     * @return JsonResponse
     */
    public function deletePlan(string $id): JsonResponse
    {
        try {
            $plan = ChargePlan::findOrFail($id);

            // Only allow deleting draft plans
//            if ($plan->status !== 'draft') {
//                throw new \Exception('Only draft plans can be deleted');
//            }

            DB::beginTransaction();
            $plan->items()->delete();
            $plan->delete();
            DB::commit();

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete charge plan: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 更新计划状态
     *
     * @param string $id
     * @param Request $request
     * @return JsonResponse
     */
    public function updatePlanStatus(string $id, Request $request): JsonResponse
    {
        try {
            $plan = ChargePlan::findOrFail($id);
            $status = $request->input('status');

            $updatedPlan = $this->giftExchangeService->updatePlanStatus($plan, $status);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedPlan->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update plan status: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 执行充值计划
     *
     * @param string $id
     * @return JsonResponse
     */
    public function executePlan(string $id): JsonResponse
    {
        try {
            $plan = ChargePlan::findOrFail($id);
            $updatedPlan = $this->giftExchangeService->executePlan($plan);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedPlan->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to execute plan: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 暂停充值计划
     *
     * @param string $id
     * @return JsonResponse
     */
    public function pausePlan(string $id): JsonResponse
    {
        try {
            $plan = ChargePlan::findOrFail($id);
            $updatedPlan = $this->giftExchangeService->pausePlan($plan);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedPlan->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to pause plan: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 恢复充值计划
     *
     * @param string $id
     * @return JsonResponse
     */
    public function resumePlan(string $id): JsonResponse
    {
        try {
            $plan = ChargePlan::findOrFail($id);
            $updatedPlan = $this->giftExchangeService->resumePlan($plan);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedPlan->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to resume plan: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 取消充值计划
     *
     * @param string $id
     * @return JsonResponse
     */
    public function cancelPlan(string $id): JsonResponse
    {
        try {
            $plan = ChargePlan::findOrFail($id);
            $updatedPlan = $this->giftExchangeService->cancelPlan($plan);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedPlan->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cancel plan: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取计划执行记录
     *
     * @param string $planId
     * @return JsonResponse
     */
    public function getPlanLogs(string $planId): JsonResponse
    {
        try {
            $plan = ChargePlan::findOrFail($planId);
            $logs = $plan->logs()->orderBy('created_at', 'desc')->get();

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'list' => $logs->map(function($log) {
                        return $log->toApiArray();
                    }),
                    'total' => $logs->count(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get plan logs: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 保存计划为模板
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function savePlanAsTemplate(Request $request): JsonResponse
    {
        try {
            $planId = $request->input('planId');
            $name = $request->input('name');
            $submittedData = $request->input('planData', []);

            // 记录接收到的数据用于调试
            Log::info('保存模板请求数据: ' . json_encode([
                'planId' => $planId,
                'name' => $name,
                'hasData' => !empty($submittedData)
            ]));

            // 检查是否是临时ID
            if (is_string($planId) && (strpos($planId, 'temp_') === 0 || !is_numeric($planId))) {
                // 这是一个临时计划
                Log::info('创建临时计划模板: ' . $planId);

                // 如果前端没有发送计划数据，使用默认值
                if (empty($submittedData)) {
                    Log::warning('未提供临时计划数据，使用默认值');
                    $submittedData = [
                        'country' => 'DEFAULT',
                        'totalAmount' => 100,
                        'days' => 5,
                        'multipleBase' => 20,
                        'floatAmount' => 5,
                        'intervalHours' => 24,
                        'items' => []
                    ];
                }

                // 创建模板
                $template = $this->giftExchangeService->createTemplateFromData($name, $submittedData);
                Log::info('成功创建模板: ' . $template->id);
            } else {
                // 查找现有计划并从中创建模板
                Log::info('从现有计划创建模板: ' . $planId);
                $plan = ChargePlan::with('items')->findOrFail($planId);
                $template = $this->giftExchangeService->createTemplateFromPlan($name, $plan);
                Log::info('成功创建模板: ' . $template->id);
            }

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'id' => (string)$template->id,
                    'name' => $template->name,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('保存计划为模板失败: ' . $e->getMessage());
            // 记录错误的详细信息
            Log::error('错误详情: ' . $e->getTraceAsString());
            return response()->json([
                'code' => 500,
                'message' => '保存模板失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 从模板创建计划
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createPlanFromTemplate(Request $request): JsonResponse
    {
        try {
            $templateId = $request->input('templateId');
            $accounts = $request->input('accounts');
            $startTime = $request->input('startTime');

            $template = ChargePlanTemplate::findOrFail($templateId);

            $result = $this->giftExchangeService->createPlansFromTemplate($template, $accounts, $startTime);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create plan from template: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取计划模板列表
     *
     * @return JsonResponse
     */
    public function getTemplates(): JsonResponse
    {
        try {
            $templates = ChargePlanTemplate::orderBy('created_at', 'desc')->get();

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'list' => $templates->map(function($template) {
                        return $template->toApiArray();
                    }),
                    'total' => $templates->count(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get templates: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 创建账号组
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createAccountGroup(Request $request): JsonResponse
    {
        try {
            $group = $this->giftExchangeService->createAccountGroup($request->all());

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $group->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create account group: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取账号组列表
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAccountGroups(Request $request): JsonResponse
    {
        try {
            $query = AccountGroup::query();

            // Apply filters
            if ($request->has('name')) {
                $query->where('name', 'like', '%' . $request->input('name') . '%');
            }

            if ($request->has('country')) {
                $query->where('country', $request->input('country'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            // Apply sorting
            $sortField = $request->input('sortField', 'created_at');
            $sortOrder = $request->input('sortOrder', 'desc');

            // Valid sort fields
            $validSortFields = ['name', 'country', 'status', 'created_at'];
            if (in_array($sortField, $validSortFields)) {
                $query->orderBy($sortField, $sortOrder === 'desc' ? 'desc' : 'asc');
            }

            // Apply pagination
            $page = $request->input('page', 1);
            $pageSize = $request->input('pageSize', 10);
            $groups = $query->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'list' => $groups->items() ? collect($groups->items())->map(function($group) {
                        return $group->toApiArray();
                    }) : [],
                    'total' => $groups->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get account groups: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取单个账号组
     *
     * @param string $id
     * @return JsonResponse
     */
    public function getAccountGroup(string $id): JsonResponse
    {
        try {
            $group = AccountGroup::with('plans.items')->findOrFail($id);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $group->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get account group: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 更新账号组
     *
     * @param string $id
     * @param Request $request
     * @return JsonResponse
     */
    public function updateAccountGroup(string $id, Request $request): JsonResponse
    {
        try {
            $group = AccountGroup::findOrFail($id);

            $group->update([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'country' => $request->input('country'),
                'total_target_amount' => $request->input('totalTargetAmount'),
                'auto_switch' => $request->input('autoSwitch'),
                'switch_threshold' => $request->input('switchThreshold'),
            ]);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $group->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update account group: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 删除账号组
     *
     * @param string $id
     * @return JsonResponse
     */
    public function deleteAccountGroup(string $id): JsonResponse
    {
        try {
            $group = AccountGroup::findOrFail($id);

            // Reset group_id for all plans
            $group->plans()->update(['group_id' => null]);

            // Delete the group
            $group->delete();

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete account group: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 向账号组添加计划
     *
     * @param string $groupId
     * @param Request $request
     * @return JsonResponse
     */
    public function addPlansToGroup(string $groupId, Request $request): JsonResponse
    {
        try {
            $group = AccountGroup::findOrFail($groupId);
            $planIds = $request->input('planIds');

            $updatedGroup = $this->giftExchangeService->addPlansToGroup($group, $planIds);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedGroup->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to add plans to group: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 从账号组移除计划
     *
     * @param string $groupId
     * @param Request $request
     * @return JsonResponse
     */
    public function removePlansFromGroup(string $groupId, Request $request): JsonResponse
    {
        try {
            $group = AccountGroup::findOrFail($groupId);
            $planIds = $request->input('planIds');

            $updatedGroup = $this->giftExchangeService->removePlansFromGroup($group, $planIds);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedGroup->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to remove plans from group: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 更新组内计划优先级
     *
     * @param string $groupId
     * @param Request $request
     * @return JsonResponse
     */
    public function updatePlanPriorities(string $groupId, Request $request): JsonResponse
    {
        try {
            $group = AccountGroup::findOrFail($groupId);
            $planPriorities = $request->input('planPriorities');

            $updatedGroup = $this->giftExchangeService->updatePlanPriorities($group, $planPriorities);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedGroup->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update plan priorities: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 启动账号组自动执行
     *
     * @param string $groupId
     * @return JsonResponse
     */
    public function startAccountGroup(string $groupId): JsonResponse
    {
        try {
            $group = AccountGroup::findOrFail($groupId);
            $updatedGroup = $this->giftExchangeService->startAccountGroup($group);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedGroup->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to start account group: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 暂停账号组执行
     *
     * @param string $groupId
     * @return JsonResponse
     */
    public function pauseAccountGroup(string $groupId): JsonResponse
    {
        try {
            $group = AccountGroup::findOrFail($groupId);
            $updatedGroup = $this->giftExchangeService->pauseAccountGroup($group);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $updatedGroup->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to pause account group: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取自动执行状态
     *
     * @return JsonResponse
     */
    public function getAutoExecutionStatus(): JsonResponse
    {
        try {
            $status = $this->giftExchangeService->getAutoExecutionStatus();

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $status,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get auto execution status: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 更新系统自动执行设置
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateAutoExecutionSettings(Request $request): JsonResponse
    {
        try {
            $settings = $this->giftExchangeService->updateAutoExecutionSettings($request->all());

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $settings->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update auto execution settings: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
    }
}
