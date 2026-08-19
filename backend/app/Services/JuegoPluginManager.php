<?php

namespace App\Services;

use App\Models\Juego;
use App\Models\PluginJuego;
use App\Plugins\Contracts\JuegoInterface;
use Illuminate\Support\Facades\Log;

class JuegoPluginManager
{
    private array $cache = [];

    public function getPlugin(Juego $juego): ?JuegoInterface
    {
        if (isset($this->cache[$juego->id])) {
            return $this->cache[$juego->id];
        }

        $pluginJuego = PluginJuego::where('juego_id', $juego->id)
            ->where('active', true)
            ->first();

        if (! $pluginJuego || ! $pluginJuego->class_namespace) {
            return null;
        }

        $class = $pluginJuego->class_namespace;

        if (! class_exists($class)) {
            Log::error("Plugin class not found: {$class} for juego ID {$juego->id}");

            return null;
        }

        $instance = app($class);

        if (! $instance instanceof JuegoInterface) {
            Log::error("Plugin class {$class} does not implement JuegoInterface");

            return null;
        }

        $this->cache[$juego->id] = $instance;

        return $instance;
    }

    public function getPluginByJuegoId(int $juegoId): ?JuegoInterface
    {
        $juego = Juego::find($juegoId);
        if (! $juego) {
            return null;
        }

        return $this->getPlugin($juego);
    }

    public function validarApuesta(Juego $juego, array $data): bool
    {
        $plugin = $this->getPlugin($juego);
        if (! $plugin) {
            return false;
        }

        return $plugin->validarApuesta($data);
    }

    public function calcularPremio(Juego $juego, array $apuesta, array $resultados): array
    {
        $plugin = $this->getPlugin($juego);
        if (! $plugin) {
            return ['premio_bs' => 0, 'premio_usd' => 0];
        }

        return $plugin->calcularPremio($apuesta, $resultados);
    }

    public function getOpciones(Juego $juego): array
    {
        $plugin = $this->getPlugin($juego);
        if (! $plugin) {
            return [];
        }

        return $plugin->obtenerOpciones();
    }

    public function getValidationRules(Juego $juego): array
    {
        $plugin = $this->getPlugin($juego);
        if (! $plugin) {
            return [];
        }

        return $plugin->getValidationRules();
    }

    public function getValidationMessages(Juego $juego): array
    {
        $plugin = $this->getPlugin($juego);
        if (! $plugin) {
            return [];
        }

        return $plugin->getValidationMessages();
    }

    public function getMultiplicador(Juego $juego): float
    {
        $plugin = $this->getPlugin($juego);
        if (! $plugin) {
            return 1;
        }

        return $plugin->obtenerMultiplicador();
    }
}
