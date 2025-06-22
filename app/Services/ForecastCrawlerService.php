<?php

namespace App\Services;

use App\Models\WarehouseForecast;
use DateTime;
use GuzzleHttp\Client;
use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class ForecastCrawlerService
{
    private $client;
    private $headers;
    private $progressCallback;

    public function __construct()
    {
        $this->client = new Client();
        $this->headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ];
    }

    public function onProgress(callable $callback)
    {
        $this->progressCallback = $callback;
    }

    private function reportProgress($message)
    {
        // 同时记录到日志文件
        Log::channel('forecast_crawler')->info('[预报爬虫] ' . $message);

        // 原有回调通知
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, $message);
        }
    }

    /**
     * 爬取失败时，更新预报状态并记录详细错误信息
     */
    private function handleCrawlFailure($forecastId, $errorMessage, $queueItemId)
    {
        $this->reportProgress("爬取失败，预报ID: {$forecastId}，错误: {$errorMessage}");

        // 更新预报记录状态为 ERROR，并记录错误信息
        DB::table('warehouse_forecast')
            ->where('id', $forecastId)
            ->update([
                'status' => WarehouseForecast::STATUS_ERROR,  // 使用模型常量
                'crawler_error' => $errorMessage, // 添加字段记录错误详情
                'update_time' => now(),
            ]);

        // 更新队列项状态为失败
        DB::table('warehouse_forecast_crawler_queue')
            ->where('id', $queueItemId)
            ->update([
                'status' => 3, // 失败状态
                'error_message' => $errorMessage,
                'update_time' => now(),
            ]);
    }

    /**
     * 处理队列
     */
    public function processQueue(array $forecastIds = []): void
    {
        $logPrefix = !empty($forecastIds) ? "处理指定预报IDs: " . implode(',', $forecastIds) : "处理所有待处理预报";
        $this->reportProgress("开始{$logPrefix}");

        // 获取待处理的队列项
        $query = DB::table('warehouse_forecast_crawler_queue')
            ->join('warehouse_forecast', 'warehouse_forecast_crawler_queue.forecast_id', '=', 'warehouse_forecast.id')
            ->where('warehouse_forecast_crawler_queue.status', 0)
            ->where('warehouse_forecast_crawler_queue.attempt_count', '<', 5)
            ->whereNotIn('warehouse_forecast.status', [-2, 5, 9, 10]) // 跳过系统取消、订单完成、已入库、已结算的预报
            ->where('warehouse_forecast.deleted', 0);

        // 如果指定了预报ID，则只处理这些预报
        if (!empty($forecastIds)) {
            $query->whereIn('warehouse_forecast_crawler_queue.forecast_id', $forecastIds);
        }

        $queueItems = $query->select('warehouse_forecast_crawler_queue.*')
            ->limit(10)
            ->get();

        $this->reportProgress("找到 " . count($queueItems) . " 个待处理队列项");

        foreach ($queueItems as $item) {
            try {
                $this->reportProgress("正在处理预报ID: {$item->forecast_id}, URL: {$item->goods_url}");

                // 再次检查预报状态，确保在处理过程中状态没有变化
                $forecast = DB::table('warehouse_forecast')
                    ->where('id', $item->forecast_id)
                    ->where('deleted', 0)
                    ->first();

                if (!$forecast || in_array($forecast->status, [-2, 5, 9, 10])) {
                    $this->reportProgress("预报ID: {$item->forecast_id} 状态已变更为终态，跳过处理");

                    // 将队列项标记为已完成
                    DB::table('warehouse_forecast_crawler_queue')
                        ->where('id', $item->id)
                        ->update([
                            'status' => 2, // 成功状态
                            'update_time' => now(),
                            'remarks' => '预报已达终态，无需爬取'
                        ]);

                    continue;
                }

                // 更新状态为处理中
                DB::table('warehouse_forecast_crawler_queue')
                    ->where('id', $item->id)
                    ->update([
                        'status' => 1,
                        'attempt_count' => DB::raw('attempt_count + 1'),
                        'last_attempt_time' => now(),
                        'update_time' => now(),
                    ]);

                // 爬取数据
                $result = $this->crawlUrl($item->goods_url);

                if (!empty($result)) {
                    // 打印爬取到的详细信息
                    $this->reportProgress("\n获取到的数据:");
                    $this->reportProgress("商品名称: " . $result['name']);
                    $this->reportProgress("订单状态: " . $result['status'] . " (" . $result['status_desc'] . ")");
                    $this->reportProgress("快递单号: " . ($result['shipment_no'] ?: '暂无'));
                    $this->reportProgress("承运商: " . ($result['shipment_arrive'] ?: '暂无'));
                    $this->reportProgress("快递链接: " . ($result['shipment_link'] ?: '暂无'));
                    if (isset($result['delivery_date'])) {
                        $this->reportProgress("预计送达: " . $result['delivery_date']);
                    }
                    if (isset($result['image_url'])) {
                        $this->reportProgress("商品图片: " . $result['image_url']);
                    }

                    // 更新预报信息
                    DB::table('warehouse_forecast')
                        ->where('id', $item->forecast_id)
                        ->update([
                            'product_name' => $result['name'],
                            'tracking_no' => $result['shipment_no'],
                            'status' => $result['status'],
                            'update_time' => now(),
                        ]);

                    // 更新队列状态为成功
                    DB::table('warehouse_forecast_crawler_queue')
                        ->where('id', $item->id)
                        ->update([
                            'status' => 2,
                            'update_time' => now(),
                        ]);

                    // 最终检查：如果状态为0但有快递信息，则设为已发货
                    if ($result['status'] == 0 && !empty($result['shipment_no'])) {
                        $result['status'] = 4;
                        $result['status_desc'] = '运输中';
                        $this->reportProgress("强制应用规则：有快递单号时设置状态为已发货(4)");

                        // 重新更新数据库中的状态
                        DB::table('warehouse_forecast')
                            ->where('id', $item->forecast_id)
                            ->update([
                                'status' => $result['status'],  // 使用新的状态
                                'update_time' => now(),
                            ]);
                    }

                    $this->reportProgress("\n成功处理预报ID: {$item->forecast_id}");
                } else {
                    // 爬取失败，改用专门的方法处理
                    $this->handleCrawlFailure(
                        $item->forecast_id,
                        '爬取商品信息失败，请检查商品链接是否有效',
                        $item->id
                    );

                    // 不需要立即抛出异常，让处理继续进行
                    continue;
                }
            } catch (Exception $e) {
                // 捕获异常，记录错误信息并更新状态
                $this->handleCrawlFailure(
                    $item->forecast_id,
                    '处理异常: ' . $e->getMessage(),
                    $item->id
                );

                $this->reportProgress("\n处理预报ID: {$item->forecast_id} 失败: " . $e->getMessage());
            }
        }

        $this->reportProgress("爬虫队列处理完成");
    }

    private function crawlUrl($url)
    {
        $this->reportProgress("开始爬取URL: {$url}");

        $maxAttempts = 5;
        $attempt = 0;
        $delay = 2;

        // 从URL中提取国家代码
        $country = 'US'; // 默认美国
        if (preg_match('/\/xc\/(xf|ca)\//', $url, $matches)) {
            $country = 'CA';  // xc/xf 或 xc/ca 都表示加拿大
        } elseif (preg_match('/\/xc\/us\//', $url)) {
            $country = 'US';  // xc/us 表示美国
        }

        $this->reportProgress("检测到国家代码: {$country}");

        while ($attempt < $maxAttempts) {
            try {
                // 根据国家选择代理
                $proxy = [
                    'http' => 'http://Ys00000011_-zone-custom-region-' . strtolower($country) . ':112233QQ@13373231fd719df0.arq.na.ipidea.online:2333',
                    'https' => 'http://Ys00000011_-zone-custom-region-' . strtolower($country) . ':112233QQ@13373231fd719df0.arq.na.ipidea.online:2333'
                ];

                $this->reportProgress("使用代理: " . json_encode($proxy, JSON_UNESCAPED_SLASHES));

                $response = $this->client->request('GET', $url, [
                    'proxy' => $proxy,
                    'headers' => $this->headers,
                    'timeout' => 10,
                    'verify' => false  // 如果有SSL证书问题，可以添加此选项
                ]);

                $html = $response->getBody()->getContents();
                $dom = new DOMDocument();
                @$dom->loadHTML($html);
                $xpath = new DOMXPath($dom);

                $scriptNode = $xpath->query('//script[@type="application/json" and @id="init_data"]')->item(0);

                if (!$scriptNode) {
                    throw new Exception('Init data script not found');
                }

                $scriptJsonData = $scriptNode->nodeValue;
                $jsonData = json_decode($scriptJsonData, true);

                if (!$jsonData || !isset($jsonData['orderDetail']['orderItems'])) {
                    throw new Exception('Invalid JSON data structure');
                }

                $this->reportProgress("爬取URL完成: {$url}");

                return $this->processOrderData($jsonData);

            } catch (RequestException $e) {
                // 记录详细的网络请求错误
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : '未知';
                $this->reportProgress("网络请求失败: HTTP状态码 {$statusCode}, 错误: {$e->getMessage()}");
                return null;
            } catch (Exception $e) {
                $this->reportProgress("爬取过程异常: " . $e->getMessage());
                return null;
            }
        }
    }

    private function processOrderData($jsonData)
    {
        // 记录完整的JSON数据结构（仅用于调试，生产环境请注释掉此行）
        $this->reportProgress("订单完整数据结构:\n" . json_encode(array_keys($jsonData), JSON_PRETTY_PRINT));
        $this->reportProgress("订单头部数据:\n" . json_encode($jsonData['orderDetail']['orderHeader'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 获取订单号
        $orderNum = $jsonData['orderDetail']['orderHeader']['d']['orderNumber'];
        $this->reportProgress("处理订单: {$orderNum}");

        // 初始化结果数组 - 注意设置默认状态为4(运输中)而不是0
        $result = [
            'order_sn' => $orderNum,
            'status' => 0,
            'name' => '',
            'shipment_arrive' => '',
            'shipment_no' => '',
            'shipment_link' => '',
            'status_desc' => '',
        ];

        // 获取所有订单项
        $orderItemNames = array_filter(array_keys($jsonData['orderDetail']['orderItems']), function ($item) {
            return strpos($item, 'orderItem') === 0;
        });

        $this->reportProgress("找到订单项: " . implode(", ", $orderItemNames));

        if (empty($orderItemNames)) {
            throw new Exception('No order items found');
        }

        foreach ($orderItemNames as $orderItemName) {
            $orderItem = $jsonData['orderDetail']['orderItems'][$orderItemName]['orderItemDetails']['d'];

            // 获取状态信息 - 从正确的路径获取
            $orderItemStatusTracker = $jsonData['orderDetail']['orderItems'][$orderItemName]['orderItemStatusTracker']['d'] ?? null;

            // 获取产品名称
            $productName = $orderItem['productName'] ?? '';

            // 获取发货状态
            $deliveryDate = $orderItem['deliveryDate'] ?? '';

            // 获取商品图片
            $orderImage = $orderItem['imageData']['src'] ?? '';

            // 初始化物流信息
            $carrier = '';
            $tracking = '';
            $expressUrl = '';

            // 获取物流信息
            if (isset($orderItem['trackingURLMap']) && !empty($orderItem['trackingURLMap'])) {
                $trackingUrls = $orderItem['trackingURLMap'];
                $tracking = array_key_first($trackingUrls);
                $expressUrl = current($trackingUrls);

                // 从快递链接中提取承运商
                if ($expressUrl) {
                    $parsedUrl = parse_url($expressUrl);
                    if (isset($parsedUrl['host'])) {
                        $hostParts = explode('.', $parsedUrl['host']);
                        $carrier = $hostParts[count($hostParts) - 2] ?? '';
                    }
                }
            }

            // 订单状态映射
            $statusMap = [
                'PLACED' => 1,
                'PROCESSING' => 2,
                'PREPARED_FOR_SHIPMENT' => 3,
                'SHIPPED' => 4,
                'DELIVERED' => 5,
                'Canceled' => -2,
                'CANCELLED' => -2,
                'ORDER_IN_PROGRESS' => 2,  // 修改为2(处理中)
                'OUT_FOR_DELIVERY' => 4,   // 修改为4(运输中)
                'ARRIVING_SOON' => 4,      // 修改为4(运输中)
                'DELIVERS' => 4,           // 已在运输
                'UNDER_REVIEW' => 6,
            ];

            // 状态描述映射
            $statusDescMap = [
                -2 => '已取消',
                -1 => '处理中',
                1 => '已下单',
                2 => '处理中',
                3 => '准备发货',
                4 => '运输中',
                5 => '已送达',
                6 => '审核中',
            ];

            // 订单状态检测逻辑
            $orderStatus = 0;  // 默认设为0

            // 使用正确的变量检查状态
            if ($orderItemStatusTracker && isset($orderItemStatusTracker['currentStatus'])) {
                $currentStatus = $orderItemStatusTracker['currentStatus'];
                $orderStatus = $statusMap[$currentStatus] ?? 0;
                $this->reportProgress("原始状态: {$currentStatus}, 映射后状态: {$orderStatus}");
            } elseif (in_array($deliveryDate, ['Canceled', 'Cancelled'])) {
                $orderStatus = -2;
                $this->reportProgress("订单已取消, 状态设为: {$orderStatus}");
            } else {
                // 如果没有状态但有快递单号，将状态设置为运输中
                if (!empty($tracking)) {
                    $orderStatus = 4;
                    $this->reportProgress("根据快递单号 {$tracking} 设置状态为运输中(4)");
                }
            }

            // 更新结果数组
            $result['name'] = empty($result['name']) ? $productName : $result['name'] . ', ' . $productName;
            $result['status'] = $orderStatus;
            $result['shipment_arrive'] = $carrier;
            $result['shipment_no'] = $tracking;
            $result['shipment_link'] = $expressUrl;
            $result['status_desc'] = $statusDescMap[$orderStatus] ?? '未知状态';
            $result['image_url'] = $orderImage;
            $result['delivery_date'] = $deliveryDate;
        }

        // 最终状态检查 - 确保有快递单号的订单状态至少为已发货
        if ($result['status'] < 4 && !empty($result['shipment_no'])) {
            $oldStatus = $result['status'];
            $result['status'] = 4;
            $result['status_desc'] = '运输中';
            $this->reportProgress("最终检查: 状态从 {$oldStatus} 调整为运输中(4)，因为存在快递单号: {$result['shipment_no']}");
        }

        // 验证必要字段
        if (empty($result['name'])) {
            throw new Exception('Product name not found');
        }

        $this->reportProgress("最终处理结果: " . json_encode($result, JSON_UNESCAPED_UNICODE));
        return $result;
    }

    private function log(string $message): void
    {
        Log::channel('forecast_crawler')->info('[预报爬虫] ' . $message);
    }
}
