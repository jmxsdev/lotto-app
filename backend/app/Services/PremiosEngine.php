<?php

namespace App\Services;

use App\Models\Juego;
use App\Plugins\Contracts\JuegoInterface;

/**
 * Motor de premios config-driven (D1/C, §3.3 del design motor-premios).
 *
 * Única fuente de verdad del dinero: lee `config.premios`
 * ({base, modalidades, comodines}) del juego y resuelve el multiplicador desde
 * la clave canónica que expone el plugin (`evaluarAcierto`), sin que ningún
 * plugin hardcodée multiplicadores (REQ1). Centraliza el redondeo (REQ8/D7),
 * el overlay de comodines (REQ6) y el guard de juegos inactivos (REQ7).
 *
 * Reglas de comodines:
 *   - flag / letra / numero: REEMPLAZAN el multiplicador vigente (gana el mayor)
 *   - palabra (acumulativa): SUMA +premio_multiplo sobre el multiplicador vigente
 *     (figura normal 50+20=70×; Patronus 75 120+20=140× → 10 × 140 = Bs. 1.400)
 *
 * Activado en F1b: los plugins implementan `evaluarAcierto`/`modalidadDe`
 * (JuegoInterface, design §3.3); el motor usa el adaptador directamente.
 */
class PremiosEngine
{
    public function __construct(private ?JuegoPluginManager $manager = null)
    {
        $this->manager ??= app(JuegoPluginManager::class);
    }

    /**
     * Liquida una apuesta: premio en Bs y USD redondeado a 2 decimales.
     *
     * @param  array<string, mixed>  $apuesta  (amount_bs, amount_usd, combinacion)
     * @param  array<string, mixed>  $resultados  (numeros_ganadores)
     * @return array{premio_bs: float, premio_usd: float}
     */
    public function calcular(Juego $juego, array $apuesta, array $resultados): array
    {
        if (! $juego->active) {
            return ['premio_bs' => 0.0, 'premio_usd' => 0.0];
        }

        $plugin = $this->manager->getPlugin($juego);
        if (! $plugin) {
            return ['premio_bs' => 0.0, 'premio_usd' => 0.0];
        }

        $acierto = $this->evaluarAcierto($plugin, $apuesta, $resultados);
        if (! ($acierto['coincide'] ?? false)) {
            return ['premio_bs' => 0.0, 'premio_usd' => 0.0];
        }

        $clave = $acierto['clave'] ?? 'base';
        $multiplicador = $this->multiplicadorConComodines($juego, $clave, $acierto['meta'] ?? []);

        $montoBs = (float) ($apuesta['amount_bs'] ?? 0);
        $montoUsd = (float) ($apuesta['amount_usd'] ?? 0);

        return [
            'premio_bs' => round($montoBs * $multiplicador, 2, PHP_ROUND_HALF_UP),
            'premio_usd' => round($montoUsd * $multiplicador, 2, PHP_ROUND_HALF_UP),
        ];
    }

    /**
     * Premio posible al crear la apuesta (REQ12/D9): monto × multiplicador de
     * la modalidad derivada de `combinacion` (clave `modalidad` si existe, si
     * no la deriva el plugin con `modalidadDe()`).
     *
     * @param  array<string, mixed>  $combinacion
     * @return array{premio_bs: float, premio_usd: float}
     */
    public function premioPosible(Juego $juego, array $combinacion, float $montoBs, float $montoUsd): array
    {
        if (! $juego->active) {
            return ['premio_bs' => 0.0, 'premio_usd' => 0.0];
        }

        $clave = $combinacion['modalidad'] ?? $this->modalidadDe($juego, $combinacion);
        $multiplicador = $this->multiplicadorPara($juego, (string) $clave);

        return [
            'premio_bs' => round($montoBs * $multiplicador, 2, PHP_ROUND_HALF_UP),
            'premio_usd' => round($montoUsd * $multiplicador, 2, PHP_ROUND_HALF_UP),
        ];
    }

    /**
     * Reglas de premios del juego para /juegos/{id}/reglas (D10, aditivo).
     *
     * @return array<string, mixed> {base, modalidades, comodines}
     */
    public function reglas(Juego $juego): array
    {
        return $juego->config['premios'] ?? [];
    }

    /**
     * Multiplicador de una clave canónica: `modalidades[clave] ?? base`, con
     * fallback transicional a `premio_multiplo` legacy SOLO para la base (D2).
     */
    public function multiplicadorPara(Juego $juego, string $clave): float
    {
        $premios = $juego->config['premios'] ?? [];
        $base = $premios['base'] ?? ($juego->config['premio_multiplo'] ?? 0);
        $modalidades = $premios['modalidades'] ?? [];

        return (float) ($modalidades[$clave] ?? $base);
    }

    /**
     * Overlay de comodines sobre el multiplicador vigente (REQ6, §3.3 paso 4).
     */
    private function multiplicadorConComodines(Juego $juego, string $clave, array $meta): float
    {
        $multiplicador = $this->multiplicadorPara($juego, $clave);
        $premios = $juego->config['premios'] ?? [];
        $comodines = $premios['comodines'] ?? [];

        foreach ($meta['comodines'] ?? [] as $comodinClave) {
            $comodin = $comodines[$comodinClave] ?? null;
            if (! is_array($comodin)) {
                continue;
            }

            $tipo = $comodin['tipo'] ?? null;
            $valor = (float) ($comodin['premio_multiplo'] ?? 0);

            if (in_array($tipo, ['flag', 'letra', 'numero'], true)) {
                // Reemplaza el multiplicador vigente: gana el mayor.
                $multiplicador = max($multiplicador, $valor);
            } elseif ($tipo === 'palabra' && ! empty($comodin['acumulativo'])) {
                // Suma acumulativa sobre el multiplicador vigente (+20×).
                $multiplicador += $valor;
            }
        }

        return $multiplicador;
    }

    /**
     * Forma del acierto vía el adaptador del plugin (F1b: todos los plugins
     * implementan JuegoInterface con `evaluarAcierto`).
     *
     * @return array{coincide: bool, clave: string, meta: array<string, mixed>}
     */
    private function evaluarAcierto(JuegoInterface $plugin, array $apuesta, array $resultados): array
    {
        $acierto = $plugin->evaluarAcierto($apuesta, $resultados);

        return [
            'coincide' => (bool) ($acierto['coincide'] ?? false),
            'clave' => (string) ($acierto['clave'] ?? 'base'),
            'meta' => $acierto['meta'] ?? [],
        ];
    }

    /**
     * Clave canónica de la modalidad apostada: la deriva el plugin con
     * `modalidadDe()` (D9/§3.4); default 'base' sin adaptador.
     */
    private function modalidadDe(Juego $juego, array $combinacion): string
    {
        $plugin = $this->manager->getPlugin($juego);
        if ($plugin) {
            return (string) $plugin->modalidadDe($combinacion);
        }

        return 'base';
    }
}
