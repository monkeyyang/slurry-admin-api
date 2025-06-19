<?php

namespace App\Console\Commands;

use App\Services\ForecastCrawlerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessForecastCrawlerQueue extends Command
{
    protected $signature = 'forecast:crawl';
    protected $description = '处理预报爬虫队列';

    public function handle()
    {
        Log::channel('forecast_crawler')->info('====== 开始执行预报爬虫队列处理命令 ======');
        $this->info('开始处理预报爬虫队列...');
        
        try {
            $crawler = new ForecastCrawlerService();
            
            // 获取待处理数量
            $pendingCount = \DB::table('warehouse_forecast_crawler_queue')
                ->join('warehouse_forecast', 'warehouse_forecast_crawler_queue.forecast_id', '=', 'warehouse_forecast.id')
                ->where('warehouse_forecast_crawler_queue.status', 0)
                ->where('warehouse_forecast_crawler_queue.attempt_count', '<', 5)
                ->whereNotIn('warehouse_forecast.status', [-2, 5, 9, 10])
                ->where('warehouse_forecast.deleted', 0)
                ->count();
            
            Log::channel('forecast_crawler')->info("发现 {$pendingCount} 个待处理任务");
            $this->info("发现 {$pendingCount} 个待处理任务");
            
            if ($pendingCount == 0) {
                Log::channel('forecast_crawler')->info('没有需要处理的任务，命令退出');
                $this->info('没有需要处理的任务，程序退出');
                return;
            }

            $bar = $this->output->createProgressBar($pendingCount);
            $bar->start();

            // 注册进度回调
            $crawler->onProgress(function($message) use ($bar) {
                // 注意：服务中已经处理了日志记录，这里不需要重复记录
                $this->info("\n" . $message);
                $bar->advance();
            });

            // 处理队列
            $crawler->processQueue();

            $bar->finish();
            
            $this->newLine();
            $this->info('队列处理完成！');
            Log::channel('forecast_crawler')->info('====== 预报爬虫队列处理命令执行完成 ======');
            
        } catch (\Exception $e) {
            Log::channel('forecast_crawler')->error('处理队列时发生错误：' . $e->getMessage());
            Log::channel('forecast_crawler')->error('错误堆栈: ' . $e->getTraceAsString());
            $this->error('处理队列时发生错误：' . $e->getMessage());
        }
    }
} 