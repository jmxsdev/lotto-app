<?php

namespace App\Services;

use App\Models\Juego;
use App\Models\JuegoOpcion;
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

        // REQ15/N12: se valida contra las opciones reales del juego
        // (juego_opciones, zoo propio), no contra el mapa canónico del
        // plugin. Mismo contrato que ApuestaService::createApuesta.
        $opciones = JuegoOpcion::where('juego_id', $juego->id)
            ->orderBy('numero')
            ->get()
            ->toArray();

        if (empty($opciones)) {
            $opciones = $plugin->obtenerOpciones();
        }

        return $plugin->validarApuesta($data, $opciones);
    }

    /**
     * El dinero lo decide PremiosEngine (D1/C, REQ1): la forma del acierto
     * llega vía `evaluarAcierto` del plugin y el multiplicador desde
     * `config.premios`. Sin circularidad: el engine solo llama `getPlugin`.
     */
    public function calcularPremio(Juego $juego, array $apuesta, array $resultados): array
    {
        return (new PremiosEngine($this))->calcular($juego, $apuesta, $resultados);
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
        // REQ1: el multiplicador sale de config.premios vía PremiosEngine
        // (clave base), nunca de una constante del plugin. Sin circularidad:
        // el engine solo llama getPlugin().
        return (new PremiosEngine($this))->multiplicadorPara($juego, 'base');
    }
}
