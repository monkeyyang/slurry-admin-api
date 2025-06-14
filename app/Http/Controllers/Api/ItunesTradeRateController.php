<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ItunesTradeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ItunesTradeRateController extends Controller
{
    protected ItunesTradeRateService $itunesTradeRateService;

    public function __construct(ItunesTradeRateService $itunesTradeRateService)
    {
        $this->itunesTradeRateService = $itunesTradeRateService;
    }

    /**
     * 获取 iTunes 交易汇率列表
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
                'country' => 'nullable|string|max:10',
                'countryName' => 'nullable|string|max:100',
                'uid' => 'nullable|integer',
                'userName' => 'nullable|string|max:100',
                'roomId' => 'nullable|string|max:50',
                'status' => ['nullable', Rule::in(['active', 'inactive'])],
                'keyword' => 'nullable|string|max:255',
            ]);

            // 转换参数格式以适配服务类
            $serviceParams = [
                'pageNum' => $params['page'] ?? 1,
                'pageSize' => $params['pageSize'] ?? 20,
            ];

            // 添加筛选条件
            if (!empty($params['country'])) {
                $serviceParams['country_code'] = $params['country'];
            }

            if (!empty($params['countryName'])) {
                $serviceParams['country_name'] = $params['countryName'];
            }

            if (!empty($params['uid'])) {
                $serviceParams['uid'] = $params['uid'];
            }

            if (!empty($params['userName'])) {
                $serviceParams['user_name'] = $params['userName'];
            }

            if (!empty($params['roomId'])) {
                $serviceParams['room_id'] = $params['roomId'];
            }

            if (!empty($params['status'])) {
                $serviceParams['status'] = $params['status'];
            }

            // 关键词搜索（搜索名称）
            if (!empty($params['keyword'])) {
                $serviceParams['keyword'] = $params['keyword'];
            }

            $result = $this->itunesTradeRateService->getTradeRatesWithRelations($serviceParams);

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'list' => $result['data'],
                    'total' => $result['total'],
                    'page' => $result['pageNum'],
                    'pageSize' => $result['pageSize'],
                    'totalPages' => ceil($result['total'] / $result['pageSize']),
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('获取 iTunes 交易汇率列表失败: ' . $e->getMessage(), [
                'params' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '获取交易汇率列表失败: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 获取单个 iTunes 交易汇率详情
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $result = $this->itunesTradeRateService->getTradeRateDetail($id);

            if (!$result) {
                return response()->json([
                    'code' => 404,
                    'message' => '交易汇率不存在',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('获取 iTunes 交易汇率详情失败: ' . $e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '获取交易汇率详情失败: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 创建 iTunes 交易汇率
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // 验证前端提交的参数格式
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'country' => 'required|string|max:10',
                'countryName' => 'nullable|string|max:100',
                'group_id' => 'nullable|integer',
                'cardType' => ['required', Rule::in(['fast', 'slow'])],
                'cardForm' => ['required', Rule::in(['image', 'code'])],
                'amountConstraint' => ['required', Rule::in(['all', 'multiple', 'fixed'])],
                'fixedAmounts' => 'nullable|array',
                'multipleBase' => 'nullable|numeric|min:0',
                'maxAmount' => 'nullable|numeric|min:0',
                'minAmount' => 'nullable|numeric|min:0',
                'rate' => 'required|numeric|min:0',
                'status' => ['nullable', Rule::in(['active', 'inactive'])],
                'description' => 'nullable|string|max:1000',
            ]);

            // 转换为数据库字段格式
            $data = [
                'uid' => Auth::id(), // 使用当前用户ID或默认值
                'name' => $validated['name'],
                'country_code' => $validated['country'],
                'group_id' => $validated['group_id'], // roomId 对应 group_id
                'room_id' => null, // room_id 设为空
                'card_type' => $validated['cardType'],
                'card_form' => $validated['cardForm'],
                'amount_constraint' => $validated['amountConstraint'],
                'fixed_amounts' => !empty($validated['fixedAmounts']) ? json_encode($validated['fixedAmounts']) : null,
                'multiple_base' => $validated['multipleBase'],
                'max_amount' => $validated['maxAmount'],
                'min_amount' => $validated['minAmount'],
                'rate' => $validated['rate'],
                'status' => $validated['status'] ?? 'active',
                'description' => $validated['description'],
            ];

            $tradeRate = $this->itunesTradeRateService->createOrUpdateTradeRate($data);

            return response()->json([
                'code' => 0,
                'message' => '创建成功',
                'data' => $tradeRate->toApiArray(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('创建 iTunes 交易汇率失败: ' . $e->getMessage(), [
                'data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '创建交易汇率失败: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 更新 iTunes 交易汇率
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
                        // 验证前端提交的参数格式
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'country' => 'sometimes|required|string|max:10',
                'countryName' => 'nullable|string|max:100',
                'roomId' => 'nullable|integer',
                'roomName' => 'nullable|string|max:100',
                'cardType' => ['sometimes', 'required', Rule::in(['fast', 'slow'])],
                'cardForm' => ['sometimes', 'required', Rule::in(['image', 'code'])],
                'amountConstraint' => ['sometimes', 'required', Rule::in(['all', 'multiple', 'fixed'])],
                'fixedAmounts' => 'nullable|array',
                'multipleBase' => 'nullable|numeric|min:0',
                'maxAmount' => 'nullable|numeric|min:0',
                'minAmount' => 'nullable|numeric|min:0',
                'rate' => 'sometimes|required|numeric|min:0',
                'status' => ['nullable', Rule::in(['active', 'inactive'])],
                'description' => 'nullable|string|max:1000',
            ]);

            // 转换为数据库字段格式
            $data = [];

            if (isset($validated['name'])) $data['name'] = $validated['name'];
            if (isset($validated['country'])) $data['country_code'] = $validated['country'];
            if (isset($validated['roomId'])) $data['group_id'] = $validated['roomId']; // roomId 对应 group_id
            if (isset($validated['cardType'])) $data['card_type'] = $validated['cardType'];
            if (isset($validated['cardForm'])) $data['card_form'] = $validated['cardForm'];
            if (isset($validated['amountConstraint'])) $data['amount_constraint'] = $validated['amountConstraint'];
            if (isset($validated['fixedAmounts'])) $data['fixed_amounts'] = !empty($validated['fixedAmounts']) ? json_encode($validated['fixedAmounts']) : null;
            if (isset($validated['multipleBase'])) $data['multiple_base'] = $validated['multipleBase'];
            if (isset($validated['maxAmount'])) $data['max_amount'] = $validated['maxAmount'];
            if (isset($validated['minAmount'])) $data['min_amount'] = $validated['minAmount'];
            if (isset($validated['rate'])) $data['rate'] = $validated['rate'];
            if (isset($validated['status'])) $data['status'] = $validated['status'];
            if (isset($validated['description'])) $data['description'] = $validated['description'];

            $tradeRate = $this->itunesTradeRateService->createOrUpdateTradeRate($data, $id);

            return response()->json([
                'code' => 0,
                'message' => '更新成功',
                'data' => $tradeRate->toApiArray(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('更新 iTunes 交易汇率失败: ' . $e->getMessage(), [
                'id' => $id,
                'data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '更新交易汇率失败: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 删除 iTunes 交易汇率
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $tradeRate = \App\Models\ItunesTradeRate::findOrFail($id);
            $tradeRate->delete();

            Log::info('iTunes 交易汇率删除成功', [
                'id' => $id,
                'deleted_by' => auth()->id() ?? 'System'
            ]);

            return response()->json([
                'code' => 0,
                'message' => '删除成功',
                'data' => null,
            ]);

        } catch (\Exception $e) {
            Log::error('删除 iTunes 交易汇率失败: ' . $e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '删除交易汇率失败: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 批量更新状态
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchUpdateStatus(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'required|integer|exists:itunes_trade_rates,id',
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ]);

            $updatedCount = $this->itunesTradeRateService->batchUpdateStatus($data['ids'], $data['status']);

            Log::info('iTunes 交易汇率批量状态更新成功', [
                'ids' => $data['ids'],
                'status' => $data['status'],
                'updated_count' => $updatedCount,
                'updated_by' => auth()->id() ?? 'System'
            ]);

            return response()->json([
                'code' => 0,
                'message' => '批量更新成功',
                'data' => [
                    'updated_count' => $updatedCount,
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 400,
                'message' => '参数验证失败',
                'data' => $e->errors(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('批量更新 iTunes 交易汇率状态失败: ' . $e->getMessage(), [
                'data' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '批量更新状态失败: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * 获取统计信息
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = $this->itunesTradeRateService->getStatistics();

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('获取 iTunes 交易汇率统计信息失败: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '获取统计信息失败: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }
}
