<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use App\Guards\RedisTokenGuard;
use App\Providers\RedisTokenUserProvider;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Auth::extend('redis_token', function ($app, $name, array $config) {
            return new RedisTokenGuard(new RedisTokenUserProvider(), $app['request']);
        });
    }
}
