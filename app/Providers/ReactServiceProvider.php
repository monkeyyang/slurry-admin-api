<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use React\EventLoop\Loop;

class ReactServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('loop', function () {
            return Loop::get();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
} 