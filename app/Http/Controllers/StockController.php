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
            $matchedCount = $this->stockService->matchForecast($request->warehouseId, $request->items);
            return $this->jsonOk(['matched_count' => $matchedCount]);
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
}
