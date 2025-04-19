<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AddToForecastQueue extends Command
{
    protected $signature = 'forecast:add-queue {--status=} {--limit=100}';
    protected $description = '手动将预报数据加入爬虫队列';

    public function handle()
    {
        $this->info('开始添加预报数据到爬虫队列...');

        try {
            // 构建查询
            $query = DB::table('warehouse_forecast')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('warehouse_forecast_crawler_queue')
                        ->whereRaw('warehouse_forecast_crawler_queue.forecast_id = warehouse_forecast.id');
                })
                ->where('deleted', 0);

            // 如果指定了状态，添加状态条件
            if ($this->option('status') !== null) {
                $query->where('status', $this->option('status'));
            }

            // 获取符合条件的预报数据
            $forecasts = $query->limit($this->option('limit'))->get();

            $count = count($forecasts);
            $this->info("找到 {$count} 条需要加入队列的预报数据");

            if ($count == 0) {
                $this->info('没有需要处理的数据，程序退出');
                return;
            }

            $bar = $this->output->createProgressBar($count);
            $bar->start();

            $now = now();
            $added = 0;

            foreach ($forecasts as $forecast) {
                try {
                    // 添加到爬虫队列
                    DB::table('warehouse_forecast_crawler_queue')->insert([
                        'forecast_id' => $forecast->id,
                        'goods_url' => $forecast->goods_url,
                        'status' => 0,
                        'attempt_count' => 0,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);

                    $added++;
                    $this->info("\n添加预报ID: {$forecast->id} 到队列");
                } catch (\Exception $e) {
                    $this->error("\n添加预报ID: {$forecast->id} 失败: " . $e->getMessage());
                }

                $bar->advance();
            }

            $bar->finish();
            
            $this->newLine();
            $this->info("队列添加完成！成功添加 {$added} 条数据");
            
        } catch (\Exception $e) {
            $this->error('添加队列时发生错误：' . $e->getMessage());
        }
    }
} 