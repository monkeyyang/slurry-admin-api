<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ItunesTradePlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ItunesTradePlanController extends Controller
{
    protected ItunesTradePlanService $planService;

    public function __construct(ItunesTradePlanService $planService)
    {
        $this->planService = $planService;
    }

    /**
     * 获取计划列表
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $params = $request->validate([
                'page' => 'nullable|integer|min:1',
                'pageSize' => 'nullable|integer|min:1|max:100',
                'countryId' => 'nullable|string|max:10',
                'rateId' => 'nullable|integer',
                'status' => ['nullable', Rule::in(['enabled', 'disabled'])],
                'keyword' => 'nullable|string|max:255',
            ]);

            // 转换参数格式以适配服务类
            $serviceParams = [
                'pageNum' => $params['page'] ?? 1,
                'pageSize' => $params['pageSize'] ?? 20,
            ];

            // 添加筛选条件
            if (!empty($params['countryId'])) {
                $serviceParams['country_code'] = $params['countryId'];
            }

            if (!empty($params['rateId'])) {
                $serviceParams['rate_id'] = $params['rateId'];
            }

            if (!empty($params['status'])) {
                $serviceParams['status'] = $params['status'];
            }

            if (!empty($params['keyword'])) {
                $serviceParams['keyword'] = $params['keyword'];
            }

            $result = $this->planService->getPlansWithPagination($serviceParams);

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $result,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('获取计划列表失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '获取计划列表失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 获取单个计划详情
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $result = $this->planService->getPlanDetail($id);

            if (!$result) {
                return response()->json([
                    'code' => 404,
                    'message' => '计划不存在',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('获取计划详情失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '获取计划详情失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 创建计划
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'country_code' => 'required|string|max:10',
                'rate_id' => 'required|integer',
                'plan_days' => 'required|integer|min:1',
                'float_amount' => 'nullable|numeric|min:0',
                'total_amount' => 'required|numeric|min:0',
                'exchange_interval' => 'nullable|integer|min:1',
                'day_interval' => 'nullable|integer|min:1',
                'daily_amounts' => 'required|array',
                'status' => ['nullable', Rule::in(['enabled', 'disabled'])],
                'description' => 'nullable|string|max:1000',
            ]);

            // 设置默认值
            $validated['float_amount'] = $validated['float_amount'] ?? 0;
            $validated['exchange_interval'] = $validated['exchange_interval'] ?? 5;
            $validated['day_interval'] = $validated['day_interval'] ?? 24;
            $validated['status'] = $validated['status'] ?? 'enabled';
            $validated['completed_days'] = [];

            $plan = $this->planService->createOrUpdatePlan($validated);

            return response()->json([
                'code' => 0,
                'message' => '创建成功',
                'data' => $plan->toApiArray(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('创建计划失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '创建计划失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 更新计划
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'country_code' => 'sometimes|string|max:10',
                'rate_id' => 'sometimes|integer',
                'plan_days' => 'sometimes|integer|min:1',
                'float_amount' => 'nullable|numeric|min:0',
                'total_amount' => 'sometimes|numeric|min:0',
                'exchange_interval' => 'nullable|integer|min:1',
                'day_interval' => 'nullable|integer|min:1',
                'daily_amounts' => 'sometimes|array',
                'completed_days' => 'nullable|array',
                'status' => ['nullable', Rule::in(['enabled', 'disabled'])],
                'description' => 'nullable|string|max:1000',
            ]);

            $plan = $this->planService->createOrUpdatePlan($validated, $id);

            return response()->json([
                'code' => 0,
                'message' => '更新成功',
                'data' => $plan->toApiArray(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('更新计划失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '更新计划失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 删除计划
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $success = $this->planService->deletePlan($id);

            if (!$success) {
                return response()->json([
                    'code' => 404,
                    'message' => '计划不存在',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'code' => 0,
                'message' => '删除成功',
                'data' => null,
            ]);

        } catch (\Exception $e) {
            Log::error('删除计划失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '删除计划失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 批量删除计划
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchDestroy(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'required|integer',
            ]);

            $deletedCount = $this->planService->batchDeletePlans($validated['ids']);

            return response()->json([
                'code' => 0,
                'message' => '批量删除成功',
                'data' => ['deleted_count' => $deletedCount],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('批量删除计划失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '批量删除失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 更新计划状态
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => ['required', Rule::in(['enabled', 'disabled'])],
            ]);

            $plan = $this->planService->updatePlanStatus($id, $validated['status']);

            if (!$plan) {
                return response()->json([
                    'code' => 404,
                    'message' => '计划不存在',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'code' => 0,
                'message' => '状态更新成功',
                'data' => $plan->toApiArray(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('更新计划状态失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '状态更新失败',
                'data' => null,
            ], 500);
        }
    }

    /**
     * 添加天数计划
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function addDays(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'additional_days' => 'required|integer|min:1|max:365',
            ]);

            $plan = $this->planService->addDaysToPlan($id, $validated['additional_days']);

            if (!$plan) {
                return response()->json([
                    'code' => 404,
                    'message' => '计划不存在',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'code' => 0,
                'message' => '天数添加成功',
                'data' => $plan->toApiArray(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('添加计划天数失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '添加天数失败',
                'data' => null,
            ], 500);
        }
    }
} 