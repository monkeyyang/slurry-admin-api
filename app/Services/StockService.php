<?php

namespace App\Services;

use App\Models\WarehouseStock;
use App\Models\WarehouseForecast;
use App\Models\WarehouseInventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class StockService
{
    /**
     * 批量导入库存
     * @param int $warehouseId
     * @param array $items
     * @return bool
     */
    public function batchImport(int $warehouseId, array $items): bool
    {
        DB::beginTransaction();
        try {
            foreach ($items as $item) {
                // 如果有预报ID，先验证预报信息
                if (!empty($item['forecastId'])) {
                    $forecast = WarehouseForecast::where('id', $item['forecastId'])
                        ->where('warehouse_id', $warehouseId)
                        ->where('tracking_no', $item['trackingNo'])
                        ->where('status', WarehouseForecast::STATUS_PENDING)
                        ->where('deleted', 0)
                        ->first();

                    if (!$forecast) {
                        throw new \Exception("预报信息不存在或状态异常，快递单号：{$item['trackingNo']}");
                    }
                }

                // 检查快递单号是否已存在
                $exists = WarehouseInventory::where('tracking_no', $item['trackingNo'])
                    ->where('deleted', 0)
                    ->exists();

                if ($exists) {
                    throw new \Exception("快递单号已存在：{$item['trackingNo']}");
                }

                // 创建库存记录
                if (!empty($item['forecastId'])) {
                    $status = WarehouseInventory::STATUS_STORED;
                } else {
                    $status = WarehouseInventory::STATUS_PENDING;
                }
                WarehouseInventory::create([
                    'warehouse_id' => $warehouseId,
                    'forecast_id' => $item['forecastId'] ?? null,
                    'goods_name' => $item['goodsName'],
                    'tracking_no' => $item['trackingNo'],
                    'product_code' => $item['productCode'] ?? null,
                    'status' => $status,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                // 如果有预报ID，更新预报状态
                if (!empty($item['forecastId'])) {
                    $forecast->status = WarehouseForecast::STATUS_STORED;
                    $forecast->receive_time = now();
                    $forecast->save();
                }
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 匹配预报
     * @param int $warehouseId
     * @param array $items
     * @return array
     */
    public function matchForecast(int $warehouseId, array $items): array
    {
        $trackingNos = array_column($items, 'trackingNo');

        // 查询匹配的预报信息
        $forecasts = WarehouseForecast::where('warehouse_id', $warehouseId)
            ->whereIn('tracking_no', $trackingNos)
//            ->where('status', WarehouseForecast::STATUS_PENDING)
            ->where('deleted', 0)
            ->get();

        // 组织返回数据
        $result = [];
        foreach ($trackingNos as $trackingNo) {
            $forecast = $forecasts->firstWhere('tracking_no', $trackingNo);

            if ($forecast) {
                $result[] = [
                    'tracking_no' => $trackingNo,
                    'matched' => true,
                    'forecast_id' => $forecast->id,
                    'preorder_no' => $forecast->preorder_no,
                    'customer_name' => $forecast->customer_name,
                    'product_name' => $forecast->product_name,
                    'goods_url' => $forecast->goods_url,
                    'order_number' => $forecast->order_number,
                    'product_code' => $forecast->product_code,
                    'quantity' => $forecast->quantity,
                    'status' => $forecast->status,
                    'create_time' => $forecast->create_time ? date('Y-m-d H:i:s', strtotime($forecast->create_time)) : null,
                    'receive_time' => $forecast->receive_time ? date('Y-m-d H:i:s', strtotime($forecast->receive_time)) : null,
                    'settle_time' => $forecast->settle_time ? date('Y-m-d H:i:s', strtotime($forecast->settle_time)) : null    
                ];
            } else {
                $result[] = [
                    'tracking_no' => $trackingNo,
                    'matched' => false,
                ];
            }
        }

        return $result;
    }

    /**
     * 确认入库
     */
    public function confirmStorage(int $id): bool
    {
        $inventory = WarehouseInventory::findOrFail($id);
        $inventory->status = WarehouseInventory::STATUS_STORED;
        $inventory->storage_time = now();
        $inventory->updated_by = Auth::id();
        return $inventory->save();
    }

    /**
     * 获取预报详情
     * @param int $id
     * @return array
     */
    public function getForecastDetail(int $id): array
    {
        $forecast = WarehouseForecast::with(['warehouse:id,name'])
            ->where('id', $id)
            ->where('deleted', 0)
            ->firstOrFail();

        return [
            'id' => $forecast->id,
            'preorder_no' => $forecast->preorder_no,
            'customer_id' => $forecast->customer_id,
            'customer_name' => $forecast->customer_name,
            'warehouse_id' => $forecast->warehouse_id,
            'warehouse_name' => $forecast->warehouse->name,
            'product_name' => $forecast->product_name,
            'goods_url' => $forecast->goods_url,
            'order_number' => $forecast->order_number,
            'tracking_no' => $forecast->tracking_no,
            'product_code' => $forecast->product_code,
            'quantity' => $forecast->quantity,
            'status' => $forecast->status,
            'status_text' => $forecast->status_text,
            'create_time' => $forecast->create_time?->format('Y-m-d H:i:s'),
            'receive_time' => $forecast->receive_time?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 结算库存
     */
    public function settleStock(int $id): bool
    {
        DB::beginTransaction();
        try {
            // 查找并更新库存记录
            $inventory = WarehouseInventory::where('id', $id)
                ->where('status', WarehouseInventory::STATUS_STORED)
                ->firstOrFail();

            $inventory->status = WarehouseInventory::STATUS_SETTLED;
            $inventory->settle_time = now();
            $inventory->updated_by = Auth::id();
            $inventory->save();

            // 如果有关联的预报，同时更新预报状态
            if ($inventory->forecast_id) {
                WarehouseForecast::where('id', $inventory->forecast_id)
                    ->where('deleted', 0)
                    ->update([
                        'status' => 10, // 已结算
                        'settle_time' => now(),
                        'update_time' => now()
                    ]);
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getList(array $params)
    {
        $query = WarehouseInventory::query()
            ->with(['warehouse:id,name'])
            ->where('deleted', 0);

        // 仓库筛选
        if (!empty($params['warehouseId'])) {
            $query->where('warehouse_id', $params['warehouseId']);
        }

        // 商品名称搜索
        if (!empty($params['goodsName'])) {
            $query->where('goods_name', 'like', '%' . $params['goodsName'] . '%');
        }

        // 快递单号搜索
        if (!empty($params['trackingNo'])) {
            $query->where('tracking_no', 'like', '%' . $params['trackingNo'] . '%');
        }

        // 产品编码搜索
        if (!empty($params['productCode'])) {
            $query->where('product_code', 'like', '%' . $params['productCode'] . '%');
        }

        // 状态筛选
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        // 时间范围筛选
        if (!empty($params['startTime'])) {
            $query->where('created_at', '>=', $params['startTime']);
        }
        if (!empty($params['endTime'])) {
            $query->where('created_at', '<=', $params['endTime']);
        }

        // 排序
        $query->orderBy($params['sortField'] ?? 'id', $params['sortOrder'] ?? 'desc');

        return $query->paginate($params['pageSize'] ?? 10);
    }

    public function batchDelete(array $ids): int
    {
        return WarehouseInventory::whereIn('id', $ids)
            ->update([
                'deleted' => 1,
                'updated_at' => now(),
                'updated_by' => auth()->id()
            ]);
    }

    /**
     * 检查快递单号是否已存在
     * @param int $warehouseId
     * @param array $trackingNos
     * @return array
     */
    public function checkTrackingNoExists(int $warehouseId, array $trackingNos): array
    {
        return WarehouseInventory::where('warehouse_id', $warehouseId)
            ->whereIn('tracking_no', $trackingNos)
            ->where('deleted', 0)
            ->pluck('tracking_no')
            ->toArray();
    }
}
