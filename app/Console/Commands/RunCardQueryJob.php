<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCardQueryJob;
use App\Services\CardQueryService;
use Illuminate\Console\Command;

class RunCardQueryJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cards:process-queue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '手动触发卡密查询队列处理';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('开始处理卡密查询队列...');

        try {
            // 同步执行任务
            $job = new ProcessCardQueryJob();
            $this->info('开始同步执行卡密查询队列任务');
            $job->handle(app(CardQueryService::class));
            $this->info('卡密查询队列任务执行完成');

            return 0;
        } catch (\Exception $e) {
            $this->error('处理卡密查询队列失败: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
