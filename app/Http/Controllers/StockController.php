<?php

namespace App\Http\Controllers;

use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * 库存管理控制器
 * 
 * 负责库存的导入、匹配预报、确认入库、结算等操作
 */
class StockController extends Controller {

    /**
     * 库存服务实例
     * 
     * @var StockService
     */
    protected $stockService;

    /**
     * 构造函数
     * 
     * @param StockService $stockService 库存服务实例
     */
    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    /**
     * 批量导入库存
     * 
     * 接收仓库ID和库存项列表，批量创建库存记录
     * 
     * @param Request $request 包含warehouseId和items数组的请求
     * @return JsonResponse 导入结果
     */
    public function import(Request $request)
    {
        $this->validate($request, [
            'warehouseId' => 'required|integer',
            'items' => 'required|array',
            'items.*.goodsName' => 'required|string|max:255',
            'items.*.trackingNo' => 'required|string|max:100',
            'items.*.productCode' => 'nullable|string|max:100',
            'items.*.forecastId' => 'nullable|integer',
        ]);

        try {
            $this->stockService->batchImport($request->warehouseId, $request->items);
            return $this->jsonOk();
        } catch (\Exception $e) {
            return $this->jsonError('导入失败：' . $e->getMessage());
        }
    }

    /**
     * 匹配预报信息
     * 
     * 根据快递单号匹配对应的预报记录
     * 
     * @param Request $request 包含warehouseId和items(快递单号列表)的请求
     * @return JsonResponse 匹配结果，包含匹配到的预报信息
     */
    public function match(Request $request)
    {
        $this->validate($request, [
            'warehouseId' => 'required|integer',
            'items' => 'required|array',
            'items.*.trackingNo' => 'required|string|max:100',
        ]);

        try {
            $matchedItems = $this->stockService->matchForecast($request->warehouseId, $request->items);
            return $this->jsonOk(['items' => $matchedItems]);
        } catch (\Exception $e) {
            return $this->jsonError('匹配失败：' . $e->getMessage());
        }
    }

    /**
     * 确认入库
     * 
     * 将指定ID的库存记录状态更新为已入库
     * 
     * @param int $id 库存记录ID
     * @return JsonResponse 确认结果
     */
    public function confirm($id)
    {
        try {
            if ($this->stockService->confirmStorage($id)) {
                return $this->jsonOk();
            }
            return $this->jsonError('入库确认失败');
        } catch (\Exception $e) {
            return $this->jsonError('入库确认失败：' . $e->getMessage());
        }
    }

    /**
     * 获取预报详情
     * 
     * 返回指定ID预报的详细信息
     * 
     * @param int $id 预报ID
     * @return JsonResponse 预报详情
     */
    public function getForecastDetail($id)
    {
        try {
            $forecast = $this->stockService->getForecastDetail($id);
            return $this->jsonOk($forecast);
        } catch (\Exception $e) {
            return $this->jsonError('获取预报详情失败：' . $e->getMessage());
        }
    }

    /**
     * 结算库存
     * 
     * 将指定ID的库存记录标记为已结算状态
     * 
     * @param int $id 库存记录ID
     * @return JsonResponse 结算结果
     */
    public function settle($id)
    {
        try {
            if ($this->stockService->settleStock($id)) {
                return $this->jsonOk();
            }
            return $this->jsonError('结算失败');
        } catch (\Exception $e) {
            return $this->jsonError('结算失败：' . $e->getMessage());
        }
    }

    /**
     * 获取库存列表
     * 
     * 根据请求参数过滤和分页返回库存列表
     * 支持按仓库、货品名称、快递单号、产品编码和状态等条件筛选
     *
     * @param Request $request 包含筛选条件和分页参数的请求
     * @return JsonResponse 库存列表及分页信息
     */
    public function list(Request $request): JsonResponse
    {
        $params = $request->validate([
            'warehouseId' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'nullable|string',
            'goodsName' => 'nullable|string',
            'trackingNo' => 'nullable|string',
            'productCode' => 'nullable|string',
            'status' => 'nullable|integer',
            'startTime' => 'nullable|date',
            'endTime' => 'nullable|date',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'pageNum' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1',
            'sortField' => 'nullable|string|in:id,created_at,storage_time,settle_time',
            'sortOrder' => 'nullable|string|in:asc,desc',
        ]);

        try {
            $data = $this->stockService->getList($request->all());
            return $this->jsonOk($data);
        } catch (\Exception $e) {
            return $this->jsonError('获取列表失败：' . $e->getMessage());
        }
    }

    /**
     * 批量删除库存记录
     * 
     * 删除指定ID列表的库存记录
     * 
     * @param Request $request 包含要删除的库存ID数组
     * @return JsonResponse 删除结果，包含受影响的记录数
     */
    public function batchDelete(Request $request)
    {
        $this->validate($request, [
            'ids' => 'required|array',
            'ids.*' => 'required|integer|min:1'
        ]);

        try {
            $result = $this->stockService->batchDelete($request->ids);
            return $this->jsonOk(['affected' => $result]);
        } catch (\Exception $e) {
            return $this->jsonError('批量删除失败：' . $e->getMessage());
        }
    }

    /**
     * 检查快递单号是否已存在
     * 
     * 验证指定仓库中给定的快递单号列表中哪些已经存在
     * 
     * @param Request $request 包含仓库ID和快递单号数组的请求
     * @return JsonResponse 包含已存在快递单号的响应
     */
    public function checkTrackingNoExists(Request $request)
    {
        $this->validate($request, [
            'warehouseId' => 'required|integer',
            'trackingNos' => 'required|array',
            'trackingNos.*' => 'required|string|max:100'
        ]);

        try {
            $existingNos = $this->stockService->checkTrackingNoExists(
                $request->warehouseId,
                $request->trackingNos
            );
            return $this->jsonOk(['exists' => $existingNos]);
        } catch (\Exception $e) {
            return $this->jsonError('检查失败：' . $e->getMessage());
        }
    }
}
