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
//        $schedule->command('plan:check-day-progress')->everyTenMinutes()
//                 ->name('check_plan_day_progress')
//                 ->withoutOverlapping();

        // iTunes账号管理 - 重构后的模块化命令

        // 每5分钟 - 状态维护（最频繁，处理异常状态）
//        $schedule->command('itunes:maintain-status')
//                ->everyFiveMinutes()
//                ->name('maintain_account_status')
//                ->withoutOverlapping()
//                ->runInBackground()
//                ->appendOutputTo(storage_path('logs/itunes_maintain_status.log'));
//
        // 每30分钟 - 日期推进（外部调度控制间隔）
        $schedule->command('itunes:advance-days')
                ->everyFiveMinutes()
                ->name('advance_account_days')
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/itunes_advance_days.log'));
//
//        // 每30分钟 - 零余额账号维护（较少频繁）
//        $schedule->command('itunes:maintain-zero-accounts')
//                ->everyThirtyMinutes()
//                ->name('maintain_zero_amount_accounts')
//                ->withoutOverlapping()
//                ->runInBackground()
//                ->appendOutputTo(storage_path('logs/itunes_zero_accounts.log'));
//
//        // 每15分钟 - 账号池维护（预计算可用账号池，提升兑换性能）
//        $schedule->command('pools:maintain')
//                ->everyFifteenMinutes()
//                ->name('maintain_account_pools')
//                ->withoutOverlapping()
//                ->runInBackground()
//                ->appendOutputTo(storage_path('logs/account_pools.log'));

        // 每10分钟清理超时的pending记录（兜底机制）
        $schedule->command('cleanup:pending-records --timeout=10')->everyTenMinutes()
                 ->name('cleanup_pending_records')
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
