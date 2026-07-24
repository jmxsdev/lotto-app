<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Plugins\Contracts\JuegoInterface;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('plugins', function () {
            return $this->cargarPlugins();
        });
    }

    public function boot(): void
    {
        // Cargar plugins al inicio
        $this->app->make('plugins');
    }

    protected function cargarPlugins(): array
    {
        $plugins = [];
        $path = app_path('Plugins/Juegos');

        if (!File::exists($path)) {
            return $plugins;
        }

        foreach (File::files($path) as $file) {
            $class = 'App\\Plugins\\Juegos\\' . $file->getBasename('.php');

            if (class_exists($class) && in_array(JuegoInterface::class, class_implements($class))) {
                $plugin = new $class();
                $plugins[$class] = $plugin;
                Log::info("Plugin cargado: " . $class);
            }
        }

        return $plugins;
    }
}
