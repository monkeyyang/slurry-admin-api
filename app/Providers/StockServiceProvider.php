<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\StockService;

class StockServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(StockService::class, function ($app) {
            return new StockService();
        });
    }
} 