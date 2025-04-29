<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminWarehouseController extends Controller
{
    /**
     * 获取仓库列表
     */
    public function index(Request $request)
    {
        $query = DB::table('admin_warehouse')
            ->where('deleted', 0)
            ->orderBy('id', 'desc');

        // 仓库名称筛选
        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->name . '%');
        }

        // 状态筛选
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $warehouses = $query->paginate($request->input('pageSize', 10));
        
        // 获取所有仓库ID
        $warehouseIds = collect($warehouses->items())->pluck('id')->toArray();
        
        // 获取所有仓库关联的货品
        $warehouseGoods = DB::table('admin_warehouse_goods')
            ->join('warehouse_goods', 'warehouse_goods.id', '=', 'admin_warehouse_goods.goods_id')
            ->select(
                'admin_warehouse_goods.warehouse_id',
                'warehouse_goods.id as goods_id',
                'warehouse_goods.name as goods_name'
            )
            ->whereIn('admin_warehouse_goods.warehouse_id', $warehouseIds)
            ->where('warehouse_goods.deleted', 0)
            ->get()
            ->groupBy('warehouse_id');
        
        // 获取所有仓库的入库统计信息
        $inboundStats = [];
        foreach ($warehouseIds as $warehouseId) {
            // 获取入库总量
            $totalCount = DB::table('warehouse_stock_in')
                ->where('warehouse_id', $warehouseId)
                ->where('status', 1) // 只统计正常状态的入库记录
                ->count();
                
            // 获取已结算数量
            $settledCount = DB::table('warehouse_stock_in')
                ->where('warehouse_id', $warehouseId)
                ->where('status', 1) // 只统计正常状态的入库记录
                ->where('is_settled', 1)
                ->count();
                
            // 获取未结算数量
            $unsettledCount = DB::table('warehouse_stock_in')
                ->where('warehouse_id', $warehouseId)
                ->where('status', 1) // 只统计正常状态的入库记录
                ->where('is_settled', 0)
                ->count();
                
            // 获取库存总量
            $stockQuantity = DB::table('warehouse_stock')
                ->where('warehouse_id', $warehouseId)
                ->sum('quantity');
            
            $inboundStats[$warehouseId] = [
                'total_inbound_count' => $totalCount,
                'settled_count' => $settledCount,
                'unsettled_count' => $unsettledCount,
                'stock_quantity' => $stockQuantity ?? 0
            ];
        }
        
        // 将货品信息和入库统计信息添加到每个仓库
        foreach ($warehouses->items() as $warehouse) {
            $warehouse->goods = isset($warehouseGoods[$warehouse->id]) 
                ? $warehouseGoods[$warehouse->id] 
                : [];
            
            // 添加入库统计信息
            if (isset($inboundStats[$warehouse->id])) {
                $warehouse->total_inbound_count = $inboundStats[$warehouse->id]['total_inbound_count'];
                $warehouse->settled_count = $inboundStats[$warehouse->id]['settled_count'];
                $warehouse->unsettled_count = $inboundStats[$warehouse->id]['unsettled_count'];
                $warehouse->stock_quantity = $inboundStats[$warehouse->id]['stock_quantity'];
            } else {
                $warehouse->total_inbound_count = 0;
                $warehouse->settled_count = 0;
                $warehouse->unsettled_count = 0;
                $warehouse->stock_quantity = 0;
            }
        }
        
        return $this->jsonOk($warehouses);
    }

    /**
     * 获取所有启用的仓库（用于下拉选择）
     */
    public function all()
    {
        $warehouses = DB::table('admin_warehouse')
            ->select('id', 'name')
            ->where('status', 1)
            ->where('deleted', 0)
            ->orderBy('id', 'desc')
            ->get();

        return $this->jsonOk($warehouses);
    }

    /**
     * 获取仓库详情
     */
    public function show($id)
    {
        $warehouse = DB::table('admin_warehouse')
            ->where('id', $id)
            ->where('deleted', 0)
            ->first();

        if (!$warehouse) {
            return $this->jsonError('仓库不存在');
        }

        // 获取仓库关联的货品
        $warehouseGoods = DB::table('admin_warehouse_goods')
            ->join('warehouse_goods', 'warehouse_goods.id', '=', 'admin_warehouse_goods.goods_id')
            ->select('warehouse_goods.id', 'warehouse_goods.name')
            ->where('admin_warehouse_goods.warehouse_id', $id)
            ->where('warehouse_goods.deleted', 0)
            ->get();

        $warehouse->goods = $warehouseGoods;

        return $this->jsonOk($warehouse);
    }

    /**
     * 创建仓库
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|string|max:100|unique:admin_warehouse,name,NULL,id,deleted,0',
            'status' => 'required|boolean',
            'remark' => 'nullable|string|max:255',
            'goods_ids' => 'nullable|array',
            'goods_ids.*' => 'integer|exists:warehouse_goods,id,deleted,0',
            'address' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:20',
        ]);

        DB::beginTransaction();
        try {
            // 创建仓库
            $warehouseId = DB::table('admin_warehouse')->insertGetId([
                'name' => $request->name,
                'status' => $request->status,
                'remark' => $request->remark,
                'address' => $request->address,
                'contact' => $request->contact,
                'phone' => $request->phone,
                'create_time' => now(),
                'update_time' => now(),
                'deleted' => 0,
            ]);

            // 关联货品
            if ($request->has('goods_ids') && is_array($request->goods_ids)) {
                $insertData = [];
                foreach ($request->goods_ids as $goodsId) {
                    $insertData[] = [
                        'warehouse_id' => $warehouseId,
                        'goods_id' => $goodsId,
                        'create_time' => now(),
                        'update_time' => now(),
                    ];
                }
                
                if (!empty($insertData)) {
                    DB::table('admin_warehouse_goods')->insert($insertData);
                }
            }

            DB::commit();
            return $this->jsonOk(['id' => $warehouseId], '仓库创建成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('创建仓库失败：' . $e->getMessage());
        }
    }

    /**
     * 更新仓库
     */
    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'name' => 'required|string|max:100|unique:admin_warehouse,name,'.$id.',id,deleted,0',
            'status' => 'required|boolean',
            'remark' => 'nullable|string|max:255',
            'goods_ids' => 'nullable|array',
            'goods_ids.*' => 'integer|exists:warehouse_goods,id,deleted,0',
            'address' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:20',
        ]);

        $warehouse = DB::table('admin_warehouse')
            ->where('id', $id)
            ->where('deleted', 0)
            ->first();

        if (!$warehouse) {
            return $this->jsonError('仓库不存在');
        }

        DB::beginTransaction();
        try {
            // 更新仓库信息
            DB::table('admin_warehouse')
                ->where('id', $id)
                ->update([
                    'name' => $request->name,
                    'status' => $request->status,
                    'remark' => $request->remark,
                    'address' => $request->address,
                    'contact' => $request->contact,
                    'phone' => $request->phone,
                    'update_time' => now(),
                ]);

            // 更新关联货品
            if ($request->has('goods_ids')) {
                // 删除原有关联
                DB::table('admin_warehouse_goods')
                    ->where('warehouse_id', $id)
                    ->delete();

                // 添加新关联
                if (is_array($request->goods_ids) && !empty($request->goods_ids)) {
                    $insertData = [];
                    foreach ($request->goods_ids as $goodsId) {
                        $insertData[] = [
                            'warehouse_id' => $id,
                            'goods_id' => $goodsId,
                            'create_time' => now(),
                            'update_time' => now(),
                        ];
                    }
                    
                    if (!empty($insertData)) {
                        DB::table('admin_warehouse_goods')->insert($insertData);
                    }
                }
            }

            DB::commit();
            return $this->jsonOk([], '仓库更新成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('更新仓库失败：' . $e->getMessage());
        }
    }

    /**
     * 更新仓库状态
     */
    public function updateStatus(Request $request)
    {
        $this->validate($request, [
            'id' => 'required|integer',
            'status' => 'required|boolean',
        ]);

        $warehouse = DB::table('admin_warehouse')
            ->where('id', $request->id)
            ->where('deleted', 0)
            ->first();

        if (!$warehouse) {
            return $this->jsonError('仓库不存在');
        }

        DB::table('admin_warehouse')
            ->where('id', $request->id)
            ->update([
                'status' => $request->status,
                'update_time' => now(),
            ]);

        return $this->jsonOk([], '状态更新成功');
    }

    /**
     * 删除仓库
     */
    public function destroy($id)
    {
        $warehouse = DB::table('admin_warehouse')
            ->where('id', $id)
            ->where('deleted', 0)
            ->first();

        if (!$warehouse) {
            return $this->jsonError('仓库不存在');
        }

        DB::beginTransaction();
        try {
            // 软删除仓库
            DB::table('admin_warehouse')
                ->where('id', $id)
                ->update([
                    'deleted' => 1,
                    'update_time' => now(),
                ]);

            // 删除仓库与货品的关联
            DB::table('admin_warehouse_goods')
                ->where('warehouse_id', $id)
                ->delete();

            DB::commit();
            return $this->jsonOk([], '仓库删除成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('删除仓库失败：' . $e->getMessage());
        }
    }

    /**
     * 获取仓库可入库货品列表
     */
    public function getWarehouseGoods($id)
    {
        $warehouse = DB::table('admin_warehouse')
            ->where('id', $id)
            ->where('deleted', 0)
            ->first();

        if (!$warehouse) {
            return $this->jsonError('仓库不存在');
        }

        $goods = DB::table('admin_warehouse_goods')
            ->join('warehouse_goods', 'warehouse_goods.id', '=', 'admin_warehouse_goods.goods_id')
            ->select('warehouse_goods.id', 'warehouse_goods.name')
            ->where('admin_warehouse_goods.warehouse_id', $id)
            ->where('warehouse_goods.deleted', 0)
            ->get();

        return $this->jsonOk($goods);
    }

    /**
     * 获取仓库列表
     */
    public function list(Request $request)
    {
        $query = DB::table('admin_warehouse')
            ->where('status', 1);
            
        // 添加搜索条件
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('code', 'like', "%{$keyword}%");
            });
        }
        
        // 获取仓库基本信息（带分页）
        $page = $request->input('page', 1);
        $pageSize = $request->input('pageSize', 10);
        $offset = ($page - 1) * $pageSize;

        $total = $query->count();
        $warehouses = $query->offset($offset)->limit($pageSize)->get();
        
        // 获取每个仓库的入库统计信息
        $result = [];
        foreach ($warehouses as $warehouse) {
            // 获取入库总量
            $totalCount = DB::table('warehouse_stock_in')
                ->where('warehouse_id', $warehouse->id)
                ->where('status', 1) // 只统计正常状态的入库记录
                ->count();
                
            // 获取已结算数量
            $settledCount = DB::table('warehouse_stock_in')
                ->where('warehouse_id', $warehouse->id)
                ->where('status', 1) // 只统计正常状态的入库记录
                ->where('is_settled', 1)
                ->count();
                
            // 获取未结算数量
            $unsettledCount = DB::table('warehouse_stock_in')
                ->where('warehouse_id', $warehouse->id)
                ->where('status', 1) // 只统计正常状态的入库记录
                ->where('is_settled', 0)
                ->count();
                
            // 获取库存总量
            $stockQuantity = DB::table('warehouse_stock')
                ->where('warehouse_id', $warehouse->id)
                ->sum('quantity');
            
            // 构建结果
            $warehouseData = (array) $warehouse;
            $warehouseData['total_inbound_count'] = $totalCount;
            $warehouseData['settled_count'] = $settledCount;
            $warehouseData['unsettled_count'] = $unsettledCount;
            $warehouseData['stock_quantity'] = $stockQuantity ?? 0;
            
            $result[] = $warehouseData;
        }
        
        return $this->jsonOk([
            'list' => $result,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize
        ]);
    }
} 