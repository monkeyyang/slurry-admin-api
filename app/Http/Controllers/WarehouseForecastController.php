<?php

namespace App\Http\Controllers;

use App\Models\WarehouseForecast;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use App\Jobs\ProcessForecastCrawlerJob;

class WarehouseForecastController extends Controller
{
    /**
     * 获取预报列表
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = DB::table('warehouse_forecast')
            ->leftJoin('admin_users as customer', 'warehouse_forecast.customer_id', '=', 'customer.id')
            ->leftJoin('admin_warehouse', 'warehouse_forecast.warehouse_id', '=', 'admin_warehouse.id')
            ->leftJoin('countries', 'admin_warehouse.country', '=', 'countries.code') // 加入国家关联
            ->where('warehouse_forecast.deleted', 0)
            ->select([
                'warehouse_forecast.*',
                'customer.username as customer_username',
                'admin_warehouse.name as warehouse_name',
                'countries.name_zh as country_name',
                'countries.name_en as country_en_name'
            ]);

        // 判断用户角色
        $isAdmin =  $user->is_admin;
        $isWarehouseManager = in_array('warehouseManager', $user->roles);

        // 如果既不是管理员也不是仓库管理员，只能查看自己的数据
        if (!$isAdmin && !$isWarehouseManager) {
            $query->where('warehouse_forecast.customer_id', $user->id);
        }
        // 仓库管理员只能查看自己负责仓库的数据
        // elseif (in_array('warehouseManager', $user->roles)) {
        //     $managedWarehouses = DB::table('admin_warehouse_user')
        //         ->where('user_id', $user->id)
        //         ->pluck('warehouse_id')
        //         ->toArray();
        //     $query->whereIn('warehouse_forecast.warehouse_id', $managedWarehouses);
        // }
        // admin 可以查看所有数据

        // 预报编号筛选
        if ($request->filled('preorderNo')) {
            $query->where('warehouse_forecast.preorder_no', 'like', '%' . $request->preorderNo . '%');
        }

        // 客户筛选
        if ($request->filled('customerName')) {
            $query->where('customer.username', 'like', '%' . $request->customerName . '%');
        }

        // 仓库筛选
        if ($request->filled('warehouseId')) {
            // 仓库管理员只能筛选自己管理的仓库
            // if (in_array('warehouseManager', $user->roles)) {
            //     $managedWarehouses = DB::table('admin_warehouse_user')
            //         ->where('user_id', $user->id)
            //         ->pluck('warehouse_id')
            //         ->toArray();
            //     if (!in_array($request->warehouseId, $managedWarehouses)) {
            //         return $this->jsonError('您没有权限查看该仓库的数据');
            //     }
            // }
            $query->where('warehouse_forecast.warehouse_id', $request->warehouseId);
        }

        // 订单编号筛选
        if ($request->filled('orderNumber')) {
            $query->where('warehouse_forecast.order_number', 'like', '%' . $request->orderNumber . '%');
        }

        // 快递单号筛选
        if ($request->filled('trackingNo')) {
            $query->where('warehouse_forecast.tracking_no', 'like', '%' . $request->trackingNo . '%');
        }

        // 状态筛选
        if ($request->filled('status')) {
            $query->where('warehouse_forecast.status', $request->status);
        }

        // 时间范围筛选
        if ($request->filled('startTime') && $request->filled('endTime')) {
            $query->whereBetween('warehouse_forecast.create_time', [$request->startTime, $request->endTime]);
        }

        $query->orderBy('warehouse_forecast.id', 'desc');

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
            $failedUrls = [];

            foreach ($request->urls as $url) {
                $orderInfo = $this->parseOrderUrl($url);
                $orderNumber = $orderInfo['orderNumber'] ?? '';

                if (empty($orderNumber)) {
                    $failedUrls[] = [
                        'url' => $url,
                        'reason' => '无法解析订单号'
                    ];
                    continue;
                }

                // 检查订单号是否已存在
                $existingForecast = DB::table('warehouse_forecast')
                    ->where('order_number', $orderNumber)
                    ->where('deleted', 0)
                    ->first();

                if ($existingForecast) {
                    $failedUrls[] = [
                        'url' => $url,
                        'reason' => "订单号 {$orderNumber} 已存在，预报编号：{$existingForecast->preorder_no}"
                    ];
                    continue;
                }

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
                    'order_number' => $orderNumber,
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

                $createdForecasts[] = [
                    'id' => $forecastId,
                    'preorderNo' => $preorderNo,
                    'orderNumber' => $orderNumber
                ];
            }

            // 新增代码: 立即执行爬虫处理
            if (!empty($createdForecasts)) {
                try {
                    \Illuminate\Support\Facades\Log::info('====== 将预报添加到队列任务 ======');
                    \Illuminate\Support\Facades\Log::info('添加的预报IDs: ' . implode(',', array_column($createdForecasts, 'id')));

                    // 分发任务到队列，不再直接执行
                    \App\Jobs\ProcessForecastCrawlerJob::dispatch(array_column($createdForecasts, 'id'));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('====== 添加预报到队列任务失败 ======');
                    \Illuminate\Support\Facades\Log::error('错误信息: ' . $e->getMessage());
                    // 失败不影响主流程，继续返回预报添加成功
                }
            }

            DB::commit();

            $response = [
                'success' => $createdForecasts,
                'failed' => $failedUrls
            ];

            if (empty($createdForecasts) && !empty($failedUrls)) {
                $errorMessage = "所有预报添加失败：\n";
                foreach ($failedUrls as $fail) {
                    $errorMessage .= "• {$fail['reason']} (URL: {$fail['url']})\n";
                }
                return $this->jsonError($errorMessage, $response);
            } elseif (!empty($failedUrls)) {
                $successCount = count($createdForecasts);
                $failCount = count($failedUrls);
                $message = "成功添加 {$successCount} 个预报，失败 {$failCount} 个。\n失败详情：\n";
                foreach ($failedUrls as $fail) {
                    $message .= "• {$fail['reason']} (URL: {$fail['url']})\n";
                }
                return $this->jsonOk($response, $message);
            } else {
                $successCount = count($createdForecasts);
                return $this->jsonOk($response, "成功添加 {$successCount} 个预报");
            }

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

    /**
     * 批量删除预报
     */
    public function batchDestroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'required|integer|exists:warehouse_forecast,id,deleted,0'
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first());
        }

        $ids = $request->input('ids');

        DB::beginTransaction();
        try {
            // 获取要删除的预报信息，用于记录结果
            $forecasts = DB::table('warehouse_forecast')
                ->whereIn('id', $ids)
                ->where('deleted', 0)
                ->get(['id', 'preorder_no', 'order_number', 'status']);

            $now = now();
            $successCount = 0;
            $failedItems = [];

            foreach ($forecasts as $forecast) {
                try {
                    // 删除预报
                    $updated = DB::table('warehouse_forecast')
                        ->where('id', $forecast->id)
                        ->where('deleted', 0)
                        ->update([
                            'deleted' => 1,
                            'delete_time' => $now,
                            'update_time' => $now,
                        ]);

                    if ($updated) {
                        $successCount++;
                    } else {
                        $failedItems[] = [
                            'id' => $forecast->id,
                            'preorderNo' => $forecast->preorder_no,
                            'reason' => '删除失败'
                        ];
                    }
                } catch (\Exception $e) {
                    $failedItems[] = [
                        'id' => $forecast->id,
                        'preorderNo' => $forecast->preorder_no,
                        'reason' => $e->getMessage()
                    ];
                }
            }

            // 同时删除对应的爬虫队列记录
            DB::table('warehouse_forecast_crawler_queue')
                ->whereIn('forecast_id', $ids)
                ->delete();

            DB::commit();

            // 构建返回消息
            if (count($failedItems) > 0) {
                $message = "成功删除 {$successCount} 个预报，失败 " . count($failedItems) . " 个。\n失败详情：\n";
                foreach ($failedItems as $item) {
                    $message .= "• 预报编号 {$item['preorderNo']}：{$item['reason']}\n";
                }
                return $this->jsonOk([
                    'success' => $successCount,
                    'failed' => $failedItems
                ], $message);
            } else {
                return $this->jsonOk([
                    'success' => $successCount,
                    'failed' => []
                ], "成功删除 {$successCount} 个预报");
            }

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->jsonError('批量删除失败：' . $e->getMessage());
        }
    }

    /**
     * 批量添加预报到爬虫队列
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchAddToForecastCrawlerQueue(Request $request)
    {
        // 同时支持两种参数格式
        if ($request->has('ids') && !$request->has('forecast_ids')) {
            $request->merge(['forecast_ids' => $request->input('ids')]);
        }

        // 验证请求数据
        $request->validate([
            'forecast_ids' => 'required|array',
            'forecast_ids.*' => 'integer|exists:warehouse_forecast,id,deleted,0',
        ]);

        $forecastIds = $request->input('forecast_ids');
        $addedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($forecastIds as $forecastId) {
            try {
                // 检查预报是否已在队列中
                $existingQueue = DB::table('warehouse_forecast_crawler_queue')
                    ->where('forecast_id', $forecastId)
                    ->where('status', 0)
                    ->first();

                if ($existingQueue) {
                    $skippedCount++;
                    continue;
                }

                // 获取预报详情
                $forecast = DB::table('warehouse_forecast')
                    ->where('id', $forecastId)
                    ->where('deleted', 0)
                    ->first();

                // 检查预报状态 - 跳过系统取消、订单完成、已入库、已结算的预报
                if (in_array($forecast->status, [-2, 5, 9, 10])) {
                    $skippedCount++;
                    continue;
                }

                // 检查是否有URL
                if (empty($forecast->goods_url)) {
                    $skippedCount++;
                    continue;
                }

                // 修改状态为待抓取
                DB::table('warehouse_forecast')
                ->where('id', $forecastId)
                ->update([
                    'status' => WarehouseForecast::STATUS_PENDING,
                    'update_time' => now(),
                ]);

                // 添加到队列
                DB::table('warehouse_forecast_crawler_queue')->insert([
                    'forecast_id' => $forecastId,
                    'goods_url' => $forecast->goods_url,
                    'status' => 0,
                    'attempt_count' => 0,
                    'create_time' => now(),
                    'update_time' => now(),
                ]);

                $addedCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $errors[] = [
                    'forecast_id' => $forecastId,
                    'message' => $e->getMessage()
                ];
            }
        }

        // 如果有添加成功的预报，立即分发队列任务
        if ($addedCount > 0) {
            ProcessForecastCrawlerJob::dispatch();
        }

        // 返回符合要求格式的响应
        return response()->json([
            'code' => 0,
            'message' => 'ok',
            'data' => [
                'total' => count($forecastIds),
                'added' => $addedCount,
                'skipped' => $skippedCount,
                'error' => $errorCount,
                'errors' => $errors
            ]
        ]);
    }

    /**
     * 检查订单号是否已存在
     *
     * 验证给定的订单号列表中哪些已经存在于预报记录中
     *
     * @param Request $request 包含订单号数组的请求
     * @return JsonResponse 包含已存在订单号的响应
     */
    public function checkOrderNoExists(Request $request)
    {
        $this->validate($request, [
            'orderNos' => 'required|array',
            'orderNos.*' => 'required|string|max:100'
        ]);

        try {
            // 查询已存在的订单号
            $existingNos = DB::table('warehouse_forecast')
                ->whereIn('order_number', $request->orderNos)
                ->where('deleted', 0)
                ->select('order_number', 'preorder_no')
                ->get()
                ->map(function($item) {
                    return [
                        'orderNo' => $item->order_number,
                        'preorderNo' => $item->preorder_no
                    ];
                });

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'exists' => $existingNos
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '检查失败：' . $e->getMessage()
            ]);
        }
    }
}
