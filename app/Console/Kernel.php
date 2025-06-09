<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\ProcessCardQueryJob;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('forecast:crawl')->hourly();

        // 每10分钟查询一次卡密
//        $schedule->command('cards:query')->everyTenMinutes();

        // 每分钟执行卡密查询队列
        $schedule->job(new ProcessCardQueryJob())->everyMinute()
                 ->name('card_query_job')
                 ->withoutOverlapping();

        // Run the auto execution command every 15 minutes
        // $schedule->command('plans:execute')->everyFifteenMinutes();

        // 每10分钟检查计划天数进度
        $schedule->command('plan:check-day-progress')->everyTenMinutes()
                 ->name('check_plan_day_progress')
                 ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    protected $commands = [
        // ... existing commands ...
        \App\Console\Commands\ProcessGiftCardExchange::class,
    ];
}
