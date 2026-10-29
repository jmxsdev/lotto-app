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
 * Activado en F1b: los plugins implementan `evaluarAcierto`
 * (JuegoInterface, design §3.3); el motor usa el adaptador directamente.
 */
class PremiosEngine
{
    /**
     * Tiers derivados de una modalidad multi-selección: no se venden y solo
     * aparecen al liquidar cuando el acierto fue parcial. Si el juego no los
     * configura, NO pagan el `base` (sería un sobrepago): pagan 0.
     *
     * @var array<int, string>
     */
    private const TIERS_DERIVADOS = ['solo_a_b', 'cruzado_10'];

    public function __construct(private ?JuegoPluginManager $manager = null)
    {
        $this->manager ??= app(JuegoPluginManager::class);
    }

    /**
     * Liquida una apuesta: premio en Bs y USD redondeado a 2 decimales.
     *
     * @param  array<string, mixed>  $apuesta  (amount_bs, amount_usd, combinacion)
     * @param  array<string, mixed>  $resultados  (numeros_ganadores)
     * @param  array<string, mixed>|null  $premios  Snapshot de `config.premios`
     *                                              al vender (S2/D4); null = config actual
     * @return array{premio_bs: float, premio_usd: float}
     */
    public function calcular(Juego $juego, array $apuesta, array $resultados, ?array $premios = null): array
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
        $multiplicador = $this->multiplicadorConComodines($juego, $clave, $acierto['meta'] ?? [], $premios);

        $montoBs = (float) ($apuesta['amount_bs'] ?? 0);
        $montoUsd = (float) ($apuesta['amount_usd'] ?? 0);

        return [
            'premio_bs' => round($montoBs * $multiplicador, 2, PHP_ROUND_HALF_UP),
            'premio_usd' => round($montoUsd * $multiplicador, 2, PHP_ROUND_HALF_UP),
        ];
    }

    /**
     * Liquida una jugada multi-sorteo (tripleta) cuando el plugin ya confirmó
     * la combinación. El multiplicador sale de `config.premios` por la clave
     * canónica (`tripleta`); no se aplican comodines.
     *
     * @param  array<string, mixed>  $apuesta
     * @param  array{coincide: bool, clave: string, meta: array<string, mixed>}  $acierto
     * @param  array<string, mixed>|null  $premios  Snapshot de `config.premios`
     * @return array{premio_bs: float, premio_usd: float}
     */
    public function calcularMultiSorteo(Juego $juego, array $apuesta, array $acierto, ?array $premios = null): array
    {
        if (! $juego->active || ! ($acierto['coincide'] ?? false)) {
            return ['premio_bs' => 0.0, 'premio_usd' => 0.0];
        }

        $clave = (string) ($acierto['clave'] ?? 'base');
        $multiplicador = $this->multiplicadorPara($juego, $clave, $premios);

        $montoBs = (float) ($apuesta['amount_bs'] ?? 0);
        $montoUsd = (float) ($apuesta['amount_usd'] ?? 0);

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
     * Multiplicador de una clave canónica.
     *
     * Orden de resolución:
     *   1. clave configurada en `premios.modalidades` → su valor (incluye 0);
     *   2. tier derivado ausente (`solo_a_b`, `cruzado_10`) → 0;
     *   3. cualquier otra clave → `base` (con fallback legacy a
     *      `premio_multiplo`).
     *
     * El paso 2 es la diferencia con `modalidades[clave] ?? base`: un tier
     * derivado no configurado nunca debe pagar el base. No se puede aplicar
     * `array_key_exists` a secas porque `tripleta` (multi-sorteo) tampoco está
     * en los snapshots legacy y sí debe caer al base.
     *
     * @param  array<string, mixed>|null  $premios  Snapshot de `config.premios`
     *                                              (S2/D4); null = config actual
     */
    public function multiplicadorPara(Juego $juego, string $clave, ?array $premios = null): float
    {
        $premios ??= $juego->config['premios'] ?? [];
        $base = $premios['base'] ?? ($juego->config['premio_multiplo'] ?? 0);
        $modalidades = is_array($premios['modalidades'] ?? null) ? $premios['modalidades'] : [];

        if (array_key_exists($clave, $modalidades)) {
            return (float) $modalidades[$clave];
        }

        if (in_array($clave, self::TIERS_DERIVADOS, true)) {
            return 0.0;
        }

        return (float) $base;
    }

    /**
     * Overlay de comodines sobre el multiplicador vigente (REQ6, §3.3 paso 4).
     *
     * @param  array<string, mixed>|null  $premios  Snapshot de `config.premios`
     *                                              (S2/D4); null = config actual
     */
    private function multiplicadorConComodines(Juego $juego, string $clave, array $meta, ?array $premios = null): float
    {
        $multiplicador = $this->multiplicadorPara($juego, $clave, $premios);
        $premios ??= $juego->config['premios'] ?? [];
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
}
