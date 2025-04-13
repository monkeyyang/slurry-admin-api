<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;

class WarehouseStockInController extends Controller
{
    /**
     * 获取入库记录列表
     */
    public function index(Request $request)
    {
        $query = DB::table('warehouse_stock_in')
            ->join('admin_warehouse', 'admin_warehouse.id', '=', 'warehouse_stock_in.warehouse_id')
            ->join('warehouse_goods', 'warehouse_goods.id', '=', 'warehouse_stock_in.goods_id')
            ->leftJoin('admin_users', 'admin_users.id', '=', 'warehouse_stock_in.create_user_id')
            ->select(
                'warehouse_stock_in.*',
                'admin_warehouse.name as warehouse_name',
                'warehouse_goods.name as goods_name',
                'admin_users.username as create_user_name'
            )
            ->where('warehouse_stock_in.deleted', 0)
            ->orderBy('warehouse_stock_in.id', 'desc');

        // 仓库筛选
        if ($request->filled('warehouseId')) {
            $query->where('warehouse_stock_in.warehouse_id', $request->warehouseId);
        }

        // 货品筛选
        if ($request->filled('goodsId')) {
            $query->where('warehouse_stock_in.goods_id', $request->goodsId);
        }

        // 订单号筛选
        if ($request->filled('orderNumber')) {
            $query->where('warehouse_stock_in.order_number', 'like', '%' . $request->orderNumber . '%');
        }

        // 物流单号筛选
        if ($request->filled('trackingNumber')) {
            $query->where('warehouse_stock_in.tracking_number', 'like', '%' . $request->trackingNumber . '%');
        }

        // 时间范围筛选
        if ($request->filled('startTime') && $request->filled('endTime')) {
            $query->whereBetween('warehouse_stock_in.create_time', [$request->startTime, $request->endTime]);
        }

        $data = $query->paginate($request->input('pageSize', 10));
        return $this->jsonOk($data);
    }

    /**
     * 单个货物入库
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'warehouseId' => 'required|integer|exists:admin_warehouse,id,deleted,0,status,1',
            'goodsId' => 'required|integer|exists:warehouse_goods,id,deleted,0',
            'orderNumber' => 'nullable|string|max:100',
            'trackingNumber' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:10',
            'quantity' => 'required|integer|min:1',
            'remark' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first());
        }

        // 检查货品是否可以入库到该仓库
        $canStockIn = DB::table('admin_warehouse_goods')
            ->where('warehouse_id', $request->warehouseId)
            ->where('goods_id', $request->goodsId)
            ->exists();

        if (!$canStockIn) {
            return $this->jsonError('该货品不允许入库到此仓库');
        }

        DB::beginTransaction();
        try {
            // 创建入库记录
            $stockInId = DB::table('warehouse_stock_in')->insertGetId([
                'warehouse_id' => $request->warehouseId,
                'goods_id' => $request->goodsId,
                'order_number' => $request->orderNumber,
                'tracking_number' => $request->trackingNumber,
                'country' => $request->country,
                'quantity' => $request->quantity,
                'remark' => $request->remark,
                'status' => 1,
                'create_user_id' => Auth::id(),
                'create_time' => now(),
                'update_time' => now(),
            ]);

            // 更新库存
            DB::table('warehouse_stock')
                ->updateOrInsert(
                    ['warehouse_id' => $request->warehouseId, 'goods_id' => $request->goodsId],
                    ['quantity' => DB::raw('quantity + ' . $request->quantity), 'update_time' => now()]
                );

            DB::commit();
            return $this->jsonOk(['id' => $stockInId], '入库成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('入库失败：' . $e->getMessage());
        }
    }

    /**
     * 批量入库
     */
    public function batchCreate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'warehouseId' => 'required|integer|exists:admin_warehouse,id',
            'items' => 'required|array',
            'items.*.orderNo' => 'required|string',
            'items.*.goodsName' => 'required|string',
            'items.*.orderLink' => 'nullable|string',
            'items.*.logisticsLink' => 'nullable|string',
            'items.*.country' => 'required|string',
            'items.*.orderStatus' => 'nullable|string',
            'items.*.createTime' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('参数错误：' . $validator->errors()->first());
        }

        $warehouseId = $request->warehouseId;
        $items = $request->items;

        // 获取仓库信息
        $warehouse = DB::table('admin_warehouse')->where('id', $warehouseId)->first();
        if (!$warehouse) {
            return $this->jsonError('仓库不存在');
        }

        // 统计信息
        $stats = [
            'total' => count($items),
            'success' => 0,
            'fail' => 0,
            'errors' => []
        ];

        DB::beginTransaction();
        try {
            $userId = Auth::id();
            $now = date('Y-m-d H:i:s');

            foreach ($items as $index => $item) {
                // 过滤掉中文键名的字段
                $filteredItem = array_filter($item, function($key) {
                    return !preg_match('/[\x{4e00}-\x{9fa5}]/u', $key);
                }, ARRAY_FILTER_USE_KEY);

                $orderNumber = $filteredItem['orderNo'];
                $goodsName = $filteredItem['goodsName'];
                $orderLink = $filteredItem['orderLink'] ?? '';
                $trackingNumber = ''; // 物流单号留空
                $trackingLink = $filteredItem['logisticsLink'] ?? ''; // 使用logisticsLink作为物流链接
                $country = $filteredItem['country'] ?? '';
                $orderStatus = $filteredItem['orderStatus'] ?? '';
                $createTime = $filteredItem['createTime'] ?? $now;
                $quantity = 1; // 默认数量为1

                // 查找货物
                $goods = DB::table('warehouse_goods')
                    ->where('name', $goodsName)
                    ->first();

                // 如果货物不存在，创建新货物 - 移除status字段
                if (!$goods) {
                    $goodsId = DB::table('warehouse_goods')->insertGetId([
                        'name' => $goodsName,
                        'create_time' => $now
                    ]);
                } else {
                    $goodsId = $goods->id;
                }

                // 检查货物是否在仓库的可入库列表中
                $warehouseGoods = DB::table('admin_warehouse_goods')
                    ->where('warehouse_id', $warehouseId)
                    ->where('goods_id', $goodsId)
                    ->first();

                // 如果不在可入库列表中，添加到可入库列表
                if (!$warehouseGoods) {
                    DB::table('admin_warehouse_goods')->insert([
                        'warehouse_id' => $warehouseId,
                        'goods_id' => $goodsId,
                        'create_time' => $now
                    ]);
                }

                // 检查是否已经存在相同的入库记录
                $existingRecord = DB::table('warehouse_stock_in')
                    ->where('warehouse_id', $warehouseId)
                    ->where('order_number', $orderNumber)
                    ->first();

                if ($existingRecord) {
                    $stats['errors'][] = "订单 {$orderNumber} 已存在入库记录";
                    $stats['fail']++;
                    continue;
                }

                // 创建入库记录 - 使用新的字段
                $stockInId = DB::table('warehouse_stock_in')->insertGetId([
                    'warehouse_id' => $warehouseId,
                    'goods_id' => $goodsId,
                    'order_number' => $orderNumber,
                    'order_link' => $orderLink,
                    'tracking_number' => $trackingNumber,
                    'tracking_link' => $trackingLink,
                    'country' => $country,
                    'order_status' => $orderStatus,
                    'quantity' => $quantity,
                    'create_user_id' => $userId,
                    'create_time' => $createTime,
                    'status' => 1 // 正常状态
                ]);

                // 更新库存
                DB::table('warehouse_stock')
                    ->updateOrInsert(
                        ['warehouse_id' => $warehouseId, 'goods_id' => $goodsId],
                        ['quantity' => DB::raw('quantity + ' . $quantity), 'update_time' => $now]
                    );

                $stats['success']++;
            }

            DB::commit();
            return $this->jsonOk([
                'stats' => $stats
            ], '批量入库成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('批量入库失败：' . $e->getMessage());
        }
    }

    /**
     * 撤销入库
     */
    public function cancel($id)
    {
        $stockIn = DB::table('warehouse_stock_in')
            ->where('id', $id)
            ->where('status', 1)
            ->first();

        if (!$stockIn) {
            return $this->jsonError('入库记录不存在或已撤销');
        }

        DB::beginTransaction();
        try {
            // 更新入库记录状态
            DB::table('warehouse_stock_in')
                ->where('id', $id)
                ->update([
                    'status' => 0,
                    'update_time' => now(),
                ]);

            // 更新库存
            DB::table('warehouse_stock')
                ->where('warehouse_id', $stockIn->warehouse_id)
                ->where('goods_id', $stockIn->goods_id)
                ->update([
                    'quantity' => DB::raw('quantity - ' . $stockIn->quantity),
                    'update_time' => now(),
                ]);

            DB::commit();
            return $this->jsonOk([], '撤销成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('撤销失败：' . $e->getMessage());
        }
    }

    /**
     * 结算入库记录
     *
     * @param int $id 入库记录ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function settle($id)
    {
        // 检查入库记录是否存在
        $stockIn = DB::table('warehouse_stock_in')->where('id', $id)->first();
        if (!$stockIn) {
            return $this->jsonError('入库记录不存在');
        }

        // 检查是否已经结算
        if ($stockIn->is_settled) {
            return $this->jsonError('该入库记录已结算');
        }

        // 更新为已结算状态
        $userId = Auth::id();
        $now = date('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            DB::table('warehouse_stock_in')
                ->where('id', $id)
                ->update([
                    'is_settled' => 1,
                    'settle_time' => $now,
                    'settle_user_id' => $userId
                ]);

            DB::commit();
            return $this->jsonOk(null, '结算成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('结算失败：' . $e->getMessage());
        }
    }

    /**
     * 重置入库记录结算状态
     *
     * @param int $id 入库记录ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetSettle($id)
    {
        // 检查入库记录是否存在
        $stockIn = DB::table('warehouse_stock_in')->where('id', $id)->first();
        if (!$stockIn) {
            return $this->jsonError('入库记录不存在');
        }

        // 检查是否已经结算
        if (!$stockIn->is_settled) {
            return $this->jsonError('该入库记录未结算');
        }

        DB::beginTransaction();
        try {
            DB::table('warehouse_stock_in')
                ->where('id', $id)
                ->update([
                    'is_settled' => 0,
                    'settle_time' => null,
                    'settle_user_id' => null
                ]);

            DB::commit();
            return $this->jsonOk(null, '重置结算状态成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('重置结算状态失败：' . $e->getMessage());
        }
    }

    /**
     * 删除入库记录
     *
     * @param int $id 入库记录ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        // 检查入库记录是否存在且未删除
        $stockIn = DB::table('warehouse_stock_in')
            ->where('id', $id)
            ->where('deleted', 0)
            ->first();
        
        if (!$stockIn) {
            return $this->jsonError('入库记录不存在或已删除');
        }

        DB::beginTransaction();
        try {
            // 如果记录状态为正常(1)，需要减少库存
            if ($stockIn->status == 1) {
                // 更新库存
                DB::table('warehouse_stock')
                    ->where('warehouse_id', $stockIn->warehouse_id)
                    ->where('goods_id', $stockIn->goods_id)
                    ->update([
                        'quantity' => DB::raw('quantity - ' . $stockIn->quantity),
                        'update_time' => now(),
                    ]);
            }

            // 软删除入库记录
            DB::table('warehouse_stock_in')
                ->where('id', $id)
                ->update([
                    'deleted' => 1,
                    'delete_time' => now(),
                    'update_time' => now()
                ]);
                
            DB::commit();
            return $this->jsonOk(null, '删除成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('删除失败：' . $e->getMessage());
        }
    }

    /**
     * 批量删除入库记录
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDestroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:warehouse_stock_in,id',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first());
        }

        $ids = $request->ids;
        
        // 获取所有要删除的记录
        $stockInRecords = DB::table('warehouse_stock_in')
            ->whereIn('id', $ids)
            ->where('deleted', 0)
            ->get();
        
        if ($stockInRecords->isEmpty()) {
            return $this->jsonError('未找到有效的入库记录');
        }

        DB::beginTransaction();
        try {
            foreach ($stockInRecords as $stockIn) {
                // 如果记录状态为正常(1)，需要减少库存
                if ($stockIn->status == 1) {
                    // 更新库存
                    DB::table('warehouse_stock')
                        ->where('warehouse_id', $stockIn->warehouse_id)
                        ->where('goods_id', $stockIn->goods_id)
                        ->update([
                            'quantity' => DB::raw('quantity - ' . $stockIn->quantity),
                            'update_time' => now(),
                        ]);
                }
            }

            // 批量软删除入库记录
            $now = now();
            DB::table('warehouse_stock_in')
                ->whereIn('id', $ids)
                ->update([
                    'deleted' => 1,
                    'delete_time' => $now,
                    'update_time' => $now
                ]);
                
            DB::commit();
            return $this->jsonOk(null, '批量删除成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('批量删除失败：' . $e->getMessage());
        }
    }
}
