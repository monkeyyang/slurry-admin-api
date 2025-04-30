<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use App\Services\StockService;

/**
 * 仓库管理控制器
 * 
 * 负责仓库的增删改查、状态管理和关联货品管理
 */
class AdminWarehouseController extends Controller
{
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
     * 获取仓库分页列表
     *
     * 返回带分页的仓库列表，支持名称和状态筛选
     * 包含关联的货品信息和入库/库存统计数据
     *
     * @param Request $request 请求对象
     * @return JsonResponse 仓库列表及分页信息
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
        
        // 获取统计数据
        $inboundStats = $this->stockService->getWarehouseStats($warehouseIds);
        
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
     * 获取所有启用的仓库
     *
     * 用于下拉选择框等场景，仅返回启用状态的仓库
     *
     * @return JsonResponse 仓库ID和名称的列表
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
     *
     * 返回指定ID仓库的详细信息，包括关联的货品列表
     *
     * @param int $id 仓库ID
     * @return JsonResponse 仓库详情
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
     * 创建新仓库
     *
     * 支持同时设置仓库基本信息和关联的货品
     *
     * @param Request $request 请求对象，包含仓库信息和关联货品ID
     * @return JsonResponse 创建结果和新仓库ID
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
     * 更新仓库信息
     *
     * 更新指定ID仓库的信息，包括关联的货品列表
     *
     * @param Request $request 请求对象，包含更新的仓库信息
     * @param int $id 仓库ID
     * @return JsonResponse 更新结果
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
     *
     * 启用或禁用指定ID的仓库
     *
     * @param Request $request 包含ID和状态值的请求
     * @return JsonResponse 更新结果
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
     *
     * 软删除指定ID的仓库，同时删除与货品的关联关系
     *
     * @param int $id 仓库ID
     * @return JsonResponse 删除结果
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
     *
     * 返回指定仓库可以入库的货品列表
     *
     * @param int $id 仓库ID
     * @return JsonResponse 货品列表
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
     * 获取仓库列表（含统计信息）
     * 
     * 用于前台展示，返回简化的仓库列表，
     * 包含入库统计和库存统计信息
     *
     * @param Request $request 请求对象，包含筛选和分页参数
     * @return JsonResponse 仓库列表及统计信息
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
        
        // 从查询结果提取仓库ID
        $warehouseIds = collect($warehouses)->pluck('id')->toArray();
        
        // 获取统计数据
        $inboundStats = $this->stockService->getWarehouseStats($warehouseIds);
        
        // 构建结果
        $result = [];
        foreach ($warehouses as $warehouse) {
            $warehouseData = (array) $warehouse;
            
            // 添加统计数据
            if (isset($inboundStats[$warehouse->id])) {
                $warehouseData = array_merge(
                    $warehouseData, 
                    $inboundStats[$warehouse->id]
                );
            } else {
                $warehouseData['total_inbound_count'] = 0;
                $warehouseData['settled_count'] = 0;
                $warehouseData['unsettled_count'] = 0;
                $warehouseData['stock_quantity'] = 0;
            }
            
            $result[] = $warehouseData;
        }
        
        // 返回结果
        return $this->jsonOk([
            'list' => $result,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize
        ]);
    }
} 