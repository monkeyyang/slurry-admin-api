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
        $crawler = new ForecastCrawlerService();
        $crawler->processQueue();
    }
} 