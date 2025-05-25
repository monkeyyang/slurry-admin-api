<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\ForecastCrawlerService;
use Illuminate\Support\Facades\Log;

class ProcessForecastCrawlerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $forecastIds;

    /**
     * 队列连接
     *
     * @var string
     */
    public $connection = 'redis';

    /**
     * 队列名称
     *
     * @var string
     */
    public $queue = 'forecast_crawler';

    /**
     * 任务最大尝试次数
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     *
     * @var int
     */
    public int $timeout = 180;

    public function __construct(array $forecastIds = [])
    {
        $this->forecastIds = $forecastIds;
    }

    public function handle()
    {
        Log::channel('forecast_crawler')->info('====== 队列任务开始处理预报爬虫 ======');
        Log::channel('forecast_crawler')->info('处理预报IDs: ' . implode(',', $this->forecastIds));
        
        try {
            $crawler = new ForecastCrawlerService();
            $crawler->processQueue($this->forecastIds);
            
            Log::channel('forecast_crawler')->info('====== 队列任务处理预报爬虫完成 ======');
        } catch (\Exception $e) {
            Log::channel('forecast_crawler')->error('====== 队列任务处理预报爬虫失败 ======');
            Log::channel('forecast_crawler')->error('错误信息: ' . $e->getMessage());
            Log::channel('forecast_crawler')->error('错误堆栈: ' . $e->getTraceAsString());
            
            throw $e; // 重新抛出异常，让队列系统处理重试
        }
    }
} 