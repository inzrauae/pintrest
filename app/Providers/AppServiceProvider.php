<?php

namespace App\Providers;

use App\Services\Fetching\DnsHostResolver;
use App\Services\Fetching\HostResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
    }

    public function boot(): void
    {
        $key = fn (Request $request) => $request->user()?->id ?: $request->ip();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($key($request)));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        // AI/fetch-heavy endpoints.
        RateLimiter::for('generation', fn (Request $request) => Limit::perMinute(20)->by($key($request)));
        RateLimiter::for('publishing', fn (Request $request) => Limit::perMinute(30)->by($key($request)));
    }
}
