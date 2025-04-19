<?php

namespace App\Console\Commands;

use App\Services\ForecastCrawlerService;
use Illuminate\Console\Command;

class ProcessForecastCrawlerQueue extends Command
{
    protected $signature = 'forecast:crawl';
    protected $description = '处理预报爬虫队列';

    public function handle()
    {
        $this->info('开始处理预报爬虫队列...');
        
        try {
            $crawler = new ForecastCrawlerService();
            
            // 获取待处理数量
            $pendingCount = \DB::table('warehouse_forecast_crawler_queue')
                ->where('status', 0)
                ->where('attempt_count', '<', 5)
                ->count();
            
            $this->info("发现 {$pendingCount} 个待处理任务");
            
            if ($pendingCount == 0) {
                $this->info('没有需要处理的任务，程序退出');
                return;
            }

            $bar = $this->output->createProgressBar($pendingCount);
            $bar->start();

            // 注册进度回调
            $crawler->onProgress(function($message) use ($bar) {
                $this->info("\n" . $message);
                $bar->advance();
            });

            // 处理队列
            $crawler->processQueue();

            $bar->finish();
            
            $this->newLine();
            $this->info('队列处理完成！');
            
        } catch (\Exception $e) {
            $this->error('处理队列时发生错误：' . $e->getMessage());
        }
    }
} 