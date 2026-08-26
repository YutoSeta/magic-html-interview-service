<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();
        RateLimiter::for('interview-writes', fn (Request $request): Limit => Limit::perMinute((int) config('interview.writes_per_minute'))
            ->by((string) $request->bearerToken()));
        RateLimiter::for('interview-reads', fn (Request $request): Limit => Limit::perMinute((int) config('interview.reads_per_minute'))
            ->by((string) $request->bearerToken()));
    }
}
