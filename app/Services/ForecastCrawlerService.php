<?php

namespace App\Services;

use DateTime;
use GuzzleHttp\Client;
use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Exception\RequestException;

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
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, $message);
        }
    }

    public function processQueue(): void
    {
        // 获取待处理的队列项
        $queueItems = DB::table('warehouse_forecast_crawler_queue')
            ->where('status', 0)
            ->where('attempt_count', '<', 5)
            ->limit(10)
            ->get();

        foreach ($queueItems as $item) {
            try {
                $this->reportProgress("正在处理预报ID: {$item->forecast_id}, URL: {$item->goods_url}");

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

                    $this->reportProgress("\n成功处理预报ID: {$item->forecast_id}");
                } else {
                    throw new Exception('解析数据失败');
                }
            } catch (Exception $e) {
                $this->reportProgress("\n处理预报ID: {$item->forecast_id} 失败: " . $e->getMessage());
                
                // 更新队列状态为失败
                DB::table('warehouse_forecast_crawler_queue')
                    ->where('id', $item->id)
                    ->update([
                        'status' => 3,
                        'error_message' => $e->getMessage(),
                        'update_time' => now(),
                    ]);
            }
        }
    }

    private function crawlUrl($url)
    {
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

                return $this->processOrderData($jsonData);

            } catch (Exception $e) {
                $this->reportProgress("第 " . ($attempt + 1) . " 次尝试失败: " . $e->getMessage());
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw new Exception('Failed to crawl URL after ' . $maxAttempts . ' attempts: ' . $e->getMessage());
                }
                sleep($delay);
            }
        }
    }

    private function processOrderData($jsonData)
    {
        // 获取订单号
        $orderNum = $jsonData['orderDetail']['orderHeader']['d']['orderNumber'];
        
        // 初始化结果数组
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

        if (empty($orderItemNames)) {
            throw new Exception('No order items found');
        }

        foreach ($orderItemNames as $orderItemName) {
            $orderItem = $jsonData['orderDetail']['orderItems'][$orderItemName]['orderItemDetails']['d'];
            
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
                'ORDER_IN_PROGRESS' => -1,
                'OUT_FOR_DELIVERY' => 2,
                'ARRIVING_SOON' => 3,
                'DELIVERS' => 4,
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

            // 获取当前状态
            $orderStatus = 0;
            if (isset($orderItem['orderItemStatusTracker']['d']['currentStatus'])) {
                $currentStatus = $orderItem['orderItemStatusTracker']['d']['currentStatus'];
                $orderStatus = $statusMap[$currentStatus] ?? 0;
                $this->reportProgress("原始状态: {$currentStatus}, 映射后状态: {$orderStatus}");
            } elseif (in_array($deliveryDate, ['Canceled', 'Cancelled'])) {
                $orderStatus = -2;
                $this->reportProgress("订单已取消, 状态设为: {$orderStatus}");
            } else {
                $orderStatus = 0;
                $this->reportProgress("未找到状态信息, 默认状态: {$orderStatus}");
            }

            // 如果有更详细的状态信息，也一并输出
            if (isset($orderItem['orderItemStatusTracker']['d'])) {
                $statusDetails = json_encode($orderItem['orderItemStatusTracker']['d'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $this->reportProgress("状态详细信息:\n{$statusDetails}");
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

        // 验证必要字段
        if (empty($result['name'])) {
            throw new Exception('Product name not found');
        }

        return $result;
    }
}
