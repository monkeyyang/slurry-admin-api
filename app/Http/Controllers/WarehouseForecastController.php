<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class WarehouseForecastController extends Controller
{
    /**
     * 获取预报列表
     */
    public function index(Request $request)
    {
        $query = DB::table('warehouse_forecast')
            ->where('deleted', 0)
            ->orderBy('id', 'desc');

        // 预报编号筛选
        if ($request->filled('preorderNo')) {
            $query->where('preorder_no', 'like', '%' . $request->preorderNo . '%');
        }

        // 客户筛选
        if ($request->filled('customerName')) {
            $query->where('customer_name', 'like', '%' . $request->customerName . '%');
        }

        // 仓库筛选
        if ($request->filled('warehouseId')) {
            $query->where('warehouse_id', $request->warehouseId);
        }

        // 订单编号筛选
        if ($request->filled('orderNumber')) {
            $query->where('order_number', 'like', '%' . $request->orderNumber . '%');
        }

        // 快递单号筛选
        if ($request->filled('trackingNo')) {
            $query->where('tracking_no', 'like', '%' . $request->trackingNo . '%');
        }

        // 状态筛选
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // 时间范围筛选
        if ($request->filled('startTime') && $request->filled('endTime')) {
            $query->whereBetween('create_time', [$request->startTime, $request->endTime]);
        }

        $data = $query->paginate($request->input('pageSize', 10));
        return $this->jsonOk($data);
    }

    /**
     * 添加预报
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'urls' => 'required|array',
            'urls.*' => 'required|string|url',
            'warehouseId' => 'required|integer|exists:admin_warehouse,id,deleted,0',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first());
        }

        // 获取仓库信息
        $warehouse = DB::table('admin_warehouse')
            ->where('id', $request->warehouseId)
            ->where('deleted', 0)
            ->first();

        if (!$warehouse) {
            return $this->jsonError('仓库不存在');
        }

        DB::beginTransaction();
        try {
            $now = now();
            $userId = Auth::id();
            $createdForecasts = [];

            foreach ($request->urls as $url) {
                $orderInfo = $this->parseOrderUrl($url);
                
                // 生成预报编号
                $preorderNo = 'F' . date('YmdHis') . rand(1000, 9999);

                // 创建预报记录
                $forecastId = DB::table('warehouse_forecast')->insertGetId([
                    'preorder_no' => $preorderNo,
                    'customer_id' => $userId,
                    'customer_name' => Auth::user()->username,
                    'warehouse_id' => $warehouse->id,
                    'warehouse_name' => $warehouse->name,
                    'goods_url' => $url,
                    'order_number' => $orderInfo['orderNumber'] ?? '',
                    'status' => 0,
                    'create_time' => $now,
                    'update_time' => $now,
                    'create_user_id' => $userId,
                    'deleted' => 0,
                ]);

                // 添加到爬虫队列
                DB::table('warehouse_forecast_crawler_queue')->insert([
                    'forecast_id' => $forecastId,
                    'goods_url' => $url,
                    'status' => 0,
                    'attempt_count' => 0,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                $createdForecasts[] = $forecastId;
            }

            DB::commit();
            return $this->jsonOk(['ids' => $createdForecasts], '预报添加成功');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('添加预报失败：' . $e->getMessage());
        }
    }

    /**
     * 解析订单URL获取订单信息
     */
    private function parseOrderUrl($url)
    {
        // 这里需要根据实际情况实现URL解析逻辑
        // 示例：从URL中提取订单号
        preg_match('/vieworder\/([^\/]+)/', $url, $matches);
        return [
            'orderNumber' => $matches[1] ?? '',
        ];
    }

    /**
     * 取消预报
     */
    public function cancel($id)
    {
        $forecast = DB::table('warehouse_forecast')
            ->where('id', $id)
            ->where('deleted', 0)
            ->first();

        if (!$forecast) {
            return $this->jsonError('预报记录不存在');
        }

        if ($forecast->status != 0) {
            return $this->jsonError('只能取消待收货的预报');
        }

        try {
            DB::table('warehouse_forecast')
                ->where('id', $id)
                ->update([
                    'status' => 2,
                    'update_time' => now(),
                ]);

            return $this->jsonOk([], '预报取消成功');
        } catch (\Exception $e) {
            return $this->jsonError('取消预报失败：' . $e->getMessage());
        }
    }

    /**
     * 删除预报
     */
    public function destroy($id)
    {
        $forecast = DB::table('warehouse_forecast')
            ->where('id', $id)
            ->where('deleted', 0)
            ->first();

        if (!$forecast) {
            return $this->jsonError('预报记录不存在');
        }

        try {
            DB::table('warehouse_forecast')
                ->where('id', $id)
                ->update([
                    'deleted' => 1,
                    'delete_time' => now(),
                    'update_time' => now(),
                ]);

            return $this->jsonOk([], '预报删除成功');
        } catch (\Exception $e) {
            return $this->jsonError('删除预报失败：' . $e->getMessage());
        }
    }
}