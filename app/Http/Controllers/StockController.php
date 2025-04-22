<?php

namespace App\Http\Controllers;

use App\Services\StockService;
use Illuminate\Http\Request;

class StockController extends Controller {

    protected $stockService;

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

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

    public function getForecastDetail($id)
    {
        try {
            $forecast = $this->stockService->getForecastDetail($id);
            return $this->jsonOk($forecast);
        } catch (\Exception $e) {
            return $this->jsonError('获取预报详情失败：' . $e->getMessage());
        }
    }

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
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(Request $request): \Illuminate\Http\JsonResponse
    {
        $params = $request->validate([
            'warehouseId' => 'nullable|integer',
            'goodsName' => 'nullable|string',
            'trackingNo' => 'nullable|string',
            'productCode' => 'nullable|string',
            'status' => 'nullable|integer',
            'startTime' => 'nullable|date',
            'endTime' => 'nullable|date',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'sortField' => 'nullable|string|in:id,created_at,storage_time,settle_time',
            'sortOrder' => 'nullable|string|in:asc,desc',
        ]);

        try {
            $data = $this->stockService->getList($params);
            return $this->jsonOk($data);
        } catch (\Exception $e) {
            return $this->jsonError('获取列表失败：' . $e->getMessage());
        }
    }

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
