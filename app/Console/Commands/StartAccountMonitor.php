<?php

namespace App\Console\Commands;


use App\Services\Gift\AccountMonitorService;
use Illuminate\Console\Command;

class StartAccountMonitor extends Command
{
    protected $signature = 'account:monitor';
    protected $description = 'Start account status monitoring service';

    public function handle(): void
    {
        $this->info("Starting account monitoring service...");

        $monitor = new AccountMonitorService();
        $monitor->startMonitoring();
        $monitor->run();
    }
}
