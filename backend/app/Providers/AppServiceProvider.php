<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
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
        Schema::defaultStringLength(191);

        // Límite dedicado de descarga del instalador (REQ-A3): 10/min por
        // usuario autenticado, o por IP para el serve firmado (sin auth).
        RateLimiter::for('releases-download', fn ($request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));

        // Feed público del auto-update (TQ-10, D1): 120/min por IP. Generoso:
        // el updater de cada taquilla reanuda descargas parciales (Range).
        RateLimiter::for('releases-feed', fn ($request) => Limit::perMinute(120)
            ->by($request->ip()));
    }
}
