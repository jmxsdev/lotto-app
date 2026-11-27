<?php

namespace Lotto\KernelLaravel\Providers;

use Illuminate\Support\ServiceProvider;

final class KernelLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Los puertos del kernel se enlazan aquí cuando existan los adaptadores.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'kernel-migrations');
    }
}
