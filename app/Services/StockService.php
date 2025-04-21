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
     */
    public function batchImport(int $warehouseId, array $items): bool
    {
        DB::beginTransaction();
        try {
            foreach ($items as $item) {
                WarehouseInventory::create([
                    'warehouse_id' => $warehouseId,
                    'goods_name' => $item['goodsName'],
                    'tracking_no' => $item['trackingNo'],
                    'product_code' => $item['productCode'] ?? null,
                    'status' => WarehouseInventory::STATUS_PENDING,
                    'created_by' => Auth::id()
                ]);
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
     */
    public function matchForecast(int $warehouseId, array $items): int
    {
        $matchedCount = 0;
        DB::beginTransaction();
        try {
            foreach ($items as $item) {
                $forecast = WarehouseForecast::where('warehouse_id', $warehouseId)
                    ->where('tracking_no', $item['trackingNo'])
                    ->where('status', 'pending')
                    ->first();

                if ($forecast) {
                    WarehouseInventory::where('warehouse_id', $warehouseId)
                        ->where('tracking_no', $item['trackingNo'])
                        ->update([
                            'forecast_id' => $forecast->id,
                            'updated_by' => Auth::id()
                        ]);
                    $matchedCount++;
                }
            }
            DB::commit();
            return $matchedCount;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
     */
    public function getForecastDetail(int $id): ?WarehouseForecast
    {
        return WarehouseForecast::with(['customer:id,username', 'warehouse:id,name'])
            ->findOrFail($id);
    }

    /**
     * 结算库存
     */
    public function settleStock(int $id): bool
    {
        $inventory = WarehouseInventory::where('id', $id)
            ->where('status', WarehouseInventory::STATUS_STORED)
            ->firstOrFail();
            
        $inventory->status = WarehouseInventory::STATUS_SETTLED;
        $inventory->settle_time = now();
        $inventory->updated_by = Auth::id();
        return $inventory->save();
    }
} 