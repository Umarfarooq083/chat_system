<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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
        Vite::prefetch(concurrency: 3);
        Schema::defaultStringLength(191);

        RateLimiter::for('widget', function (Request $request) {
            return Limit::perMinute(800)->by(
                $request->header('X-Visitor-Id')
                    ?? $request->input('visitor_id')
                    ?? $request->query('visitor_id')
                    ?? $request->ip()
            );
        });
    }
}
