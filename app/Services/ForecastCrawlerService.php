<?php

namespace App\Services;

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

    public function __construct()
    {
        $this->client = new Client();
        $this->headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ];
    }

    public function processQueue()
    {
        // 获取待处理的队列项
        $queueItems = DB::table('warehouse_forecast_crawler_queue')
            ->where('status', 0)
            ->where('attempt_count', '<', 5)
            ->limit(10)
            ->get();

        foreach ($queueItems as $item) {
            try {
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
                    // 更新预报信息
                    DB::table('warehouse_forecast')
                        ->where('id', $item->forecast_id)
                        ->update([
                            'product_name' => $result['name'],
                            'tracking_no' => $result['shipment_no'],
                            'update_time' => now(),
                        ]);

                    // 更新队列状态为成功
                    DB::table('warehouse_forecast_crawler_queue')
                        ->where('id', $item->id)
                        ->update([
                            'status' => 2,
                            'update_time' => now(),
                        ]);
                } else {
                    throw new Exception('Failed to parse data');
                }
            } catch (Exception $e) {
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

        while ($attempt < $maxAttempts) {
            try {
                $proxy = [
                    'http' => 'http://Ys00000011_-zone-custom-region-us:112233QQ@13373231fd719df0.arq.na.ipidea.online:2333',
                    'https' => 'http://Ys00000011_-zone-custom-region-us:112233QQ@13373231fd719df0.arq.na.ipidea.online:2333'
                ];

                $response = $this->client->request('GET', $url, [
                    'proxy' => $proxy,
                    'headers' => $this->headers,
                    'timeout' => 10
                ]);

                $html = $response->getBody()->getContents();
                $dom = new DOMDocument();
                @$dom->loadHTML($html);
                $xpath = new DOMXPath($dom);
                
                $scriptJsonData = $xpath->query('//script[@type="application/json" and @id="init_data"]')->item(0)->nodeValue;
                $jsonData = json_decode($scriptJsonData, true);

                if (!$jsonData || !isset($jsonData['orderDetail']['orderItems'])) {
                    throw new Exception('Invalid JSON data');
                }

                return $this->processOrderData($jsonData);
            } catch (Exception $e) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                sleep($delay);
            }
        }
    }

    private function processOrderData($jsonData)
    {
        // 使用您提供的处理逻辑
        // ... 这里是您原有的 processOrderData 方法内容
    }
} 