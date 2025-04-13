<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WarehouseGoodsController extends Controller
{
    /**
     * 获取货品列表
     */
    public function index(Request $request)
    {
        $query = DB::table('warehouse_goods')
            ->select('warehouse_goods.*')
            ->where('warehouse_goods.deleted', 0)
            ->orderBy('warehouse_goods.id', 'desc');

        // 货品名称筛选
        if ($request->filled('name')) {
            $query->where('warehouse_goods.name', 'like', '%' . $request->name . '%');
        }

        // 时间范围筛选
        if ($request->filled('startTime') && $request->filled('endTime')) {
            $query->whereBetween('warehouse_goods.create_time', [$request->startTime, $request->endTime]);
        } else if ($request->filled('startTime')) {
            $query->where('warehouse_goods.create_time', '>=', $request->startTime);
        } else if ($request->filled('endTime')) {
            $query->where('warehouse_goods.create_time', '<=', $request->endTime);
        }

        $data = $query->paginate($request->input('pageSize', 10));

        // 获取每个货品的别名
        $goodsIds = $data->pluck('id')->toArray();
        $aliases = DB::table('warehouse_goods_alias')
            ->whereIn('goods_id', $goodsIds)
            ->where('deleted', 0)
            ->get()
            ->groupBy('goods_id');

        // 将别名添加到货品数据中
        foreach ($data as $goods) {
            $goods->aliases = isset($aliases[$goods->id]) ? $aliases[$goods->id] : [];
        }

        return $this->jsonOk($data);
    }

    /**
     * 获取货品详情
     */
    public function show($id)
    {
        $goods = DB::table('warehouse_goods')
            ->where('id', $id)
            ->where('deleted', 0)
            ->first();

        if (!$goods) {
            return $this->jsonError('货品不存在');
        }

        // 获取货品别名
        $aliases = DB::table('warehouse_goods_alias')
            ->where('goods_id', $id)
            ->where('deleted', 0)
            ->get();

        $goods->aliases = $aliases;

        return $this->jsonOk($goods);
    }

    /**
     * 创建货品
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'aliases' => 'nullable|array',
            'aliases.*.name' => 'required|string|max:255',
            'aliases.*.region' => 'required|string|max:10',
        ]);

        DB::beginTransaction();
        try {
            // 创建货品
            $goodsId = DB::table('warehouse_goods')->insertGetId([
                'name' => $request->name,
                'create_time' => now(),
                'update_time' => now(),
                'deleted' => 0,
            ]);

            // 创建货品别名
            if ($request->has('aliases') && is_array($request->aliases)) {
                foreach ($request->aliases as $alias) {
                    DB::table('warehouse_goods_alias')->insert([
                        'goods_id' => $goodsId,
                        'name' => $alias['name'],
                        'region' => $alias['region'],
                        'create_time' => now(),
                        'update_time' => now(),
                        'deleted' => 0,
                    ]);
                }
            }

            DB::commit();
            return $this->jsonOk(['id' => $goodsId], '货品创建成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('创建货品失败：' . $e->getMessage());
        }
    }

    /**
     * 更新货品
     */
    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'aliases' => 'nullable|array',
            'aliases.*.id' => 'nullable|integer',
            'aliases.*.name' => 'required|string|max:255',
            'aliases.*.region' => 'required|string|max:10',
        ]);

        $goods = DB::table('warehouse_goods')
            ->where('id', $id)
            ->where('deleted', 0)
            ->first();

        if (!$goods) {
            return $this->jsonError('货品不存在');
        }

        DB::beginTransaction();
        try {
            // 更新货品
            DB::table('warehouse_goods')
                ->where('id', $id)
                ->update([
                    'name' => $request->name,
                    'update_time' => now(),
                ]);

            // 处理别名
            if ($request->has('aliases') && is_array($request->aliases)) {
                // 获取现有别名
                $existingAliases = DB::table('warehouse_goods_alias')
                    ->where('goods_id', $id)
                    ->where('deleted', 0)
                    ->get()
                    ->keyBy('id');

                // 处理提交的别名
                foreach ($request->aliases as $alias) {
                    if (isset($alias['id']) && $alias['id'] > 0) {
                        // 更新现有别名
                        if (isset($existingAliases[$alias['id']])) {
                            DB::table('warehouse_goods_alias')
                                ->where('id', $alias['id'])
                                ->update([
                                    'name' => $alias['name'],
                                    'region' => $alias['region'],
                                    'update_time' => now(),
                                ]);
                            // 从现有别名集合中移除已处理的别名
                            unset($existingAliases[$alias['id']]);
                        }
                    } else {
                        // 添加新别名
                        DB::table('warehouse_goods_alias')->insert([
                            'goods_id' => $id,
                            'name' => $alias['name'],
                            'region' => $alias['region'],
                            'create_time' => now(),
                            'update_time' => now(),
                            'deleted' => 0,
                        ]);
                    }
                }

                // 删除未在提交数据中的别名
                foreach ($existingAliases as $alias) {
                    DB::table('warehouse_goods_alias')
                        ->where('id', $alias->id)
                        ->update([
                            'deleted' => 1,
                            'update_time' => now(),
                        ]);
                }
            }

            DB::commit();
            return $this->jsonOk([], '货品更新成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('更新货品失败：' . $e->getMessage());
        }
    }

    /**
     * 删除货品
     */
    public function destroy($id)
    {
        $goods = DB::table('warehouse_goods')
            ->where('id', $id)
            ->where('deleted', 0)
            ->first();

        if (!$goods) {
            return $this->jsonError('货品不存在');
        }

        // 检查是否有库存
        $hasStock = DB::table('warehouse_stock')
            ->where('goods_id', $id)
            ->where('quantity', '>', 0)
            ->exists();

        if ($hasStock) {
            return $this->jsonError('该货品有库存记录，无法删除');
        }

        // 检查是否有入库记录
        $hasStockIn = DB::table('warehouse_stock_in')
            ->where('goods_id', $id)
            ->where('deleted', 0)
            ->exists();

        if ($hasStockIn) {
            return $this->jsonError('该货品有入库记录，无法删除');
        }

        // 检查是否有出库记录
//        $hasStockOut = DB::table('warehouse_stock_out')
//            ->where('goods_id', $id)
//            ->where('deleted', 0)
//            ->exists();
//
//        if ($hasStockOut) {
//            return $this->jsonError('该货品有出库记录，无法删除');
//        }

        DB::beginTransaction();
        try {
            $now = now();

            // 软删除货品
            DB::table('warehouse_goods')
                ->where('id', $id)
                ->update([
                    'deleted' => 1,
                    'update_time' => now(),
                ]);

            // 软删除货品别名
            DB::table('warehouse_goods_alias')
                ->where('goods_id', $id)
                ->update([
                    'deleted' => 1,
                    'update_time' => now(),
                ]);

            DB::commit();
            return $this->jsonOk([], '货品删除成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('删除货品失败：' . $e->getMessage());
        }
    }

    /**
     * 删除货品别名
     */
    public function destroyAlias($id)
    {
        $alias = DB::table('warehouse_goods_alias')
            ->where('id', $id)
            ->where('deleted', 0)
            ->first();

        if (!$alias) {
            return $this->jsonError('别名不存在');
        }

        try {
            // 软删除别名
            DB::table('warehouse_goods_alias')
                ->where('id', $id)
                ->update([
                    'deleted' => 1,
                    'update_time' => now(),
                ]);

            return $this->jsonOk([], '别名删除成功');
        } catch (\Exception $e) {
            return $this->jsonError('删除别名失败：' . $e->getMessage());
        }
    }

    /**
     * 批量删除货品
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDestroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:warehouse_goods,id,deleted,0',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first());
        }

        $ids = $request->ids;

        // 检查是否有库存
        $hasStock = DB::table('warehouse_stock')
            ->whereIn('goods_id', $ids)
            ->where('quantity', '>', 0)
            ->exists();

        if ($hasStock) {
            return $this->jsonError('选中的货品中有库存记录，无法删除');
        }

        // 检查是否有入库记录
        $hasStockIn = DB::table('warehouse_stock_in')
            ->whereIn('goods_id', $ids)
            ->where('deleted', 0)
            ->exists();

        if ($hasStockIn) {
            return $this->jsonError('选中的货品中有入库记录，无法删除');
        }

        // 检查是否有出库记录
//        $hasStockOut = DB::table('warehouse_stock_out')
//            ->whereIn('goods_id', $ids)
//            ->where('deleted', 0)
//            ->exists();
//
//        if ($hasStockOut) {
//            return $this->jsonError('选中的货品中有出库记录，无法删除');
//        }

        DB::beginTransaction();
        try {
            // 批量软删除货品
            $now = now();
            DB::table('warehouse_goods')
                ->whereIn('id', $ids)
                ->update([
                    'deleted' => 1,
                    'update_time' => $now
                ]);

            // 批量软删除货品别名
            DB::table('warehouse_goods_alias')
                ->whereIn('goods_id', $ids)
                ->update([
                    'deleted' => 1,
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
