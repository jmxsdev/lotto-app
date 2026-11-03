<?php

namespace App\Services;

use App\Models\Apuesta;
use App\Models\Resultado;

/**
 * Motor único de liquidación de premios (WU5).
 *
 * Extracción behavior-neutral de `PagoController::calcularPremio` y
 * `esMultiSorteo`: el controlador de egreso y el comando de auditoría legacy
 * comparten exactamente el mismo camino de cálculo (config-driven, snapshot
 * `premios_snapshot`, tripleta multi-sorteo y fallback legacy).
 */
final class PremiosLiquidador
{
    public function __construct(
        private readonly JuegoPluginManager $manager,
    ) {}

    /**
     * Liquidar el premio de una apuesta con el motor vigente.
     *
     * @return array{premio_bs: float, premio_usd: float}
     */
    public function calcular(Apuesta $apuesta, ?Resultado $resultado): array
    {
        $combinacion = is_string($apuesta->combinacion)
            ? json_decode($apuesta->combinacion, true)
            : $apuesta->combinacion;

        $combinacion = is_array($combinacion) ? $combinacion : [];

        // Invariante vigente: UN detalle por apuesta. Sin detalle — o sin
        // snapshot — la resolución cae al config.premios actual (fallback
        // legacy) y el flujo sigue.
        $detalle = $apuesta->relationLoaded('detalles')
            ? $apuesta->detalles->first()
            : $apuesta->detalles()->first();
        $premiosSnapshot = $detalle?->premios_snapshot;

        // Tripleta multi-sorteo: el premio se recalcula con la evidencia de
        // los resultados guardados al liquidar, no con un único resultado.
        if ($this->esMultiSorteo($combinacion)) {
            $ids = $apuesta->resultado_ids ?? [];

            if (empty($ids)) {
                return ['premio_bs' => 0.0, 'premio_usd' => 0.0];
            }

            $resultados = Resultado::whereIn('id', $ids)
                ->where('juego_id', $apuesta->juego_id)
                ->orderBy('hora_sorteo')
                ->get()
                ->map(fn (Resultado $r) => [
                    'id' => $r->id,
                    'hora_sorteo' => $r->hora_sorteo,
                    'numeros_ganadores' => $r->numeros_ganadores,
                ])
                ->values()
                ->all();

            $acierto = $this->manager->evaluarMultiSorteo(
                $apuesta->juego,
                ['combinacion' => $combinacion],
                $resultados
            );

            if (! ($acierto['coincide'] ?? false)) {
                return ['premio_bs' => 0.0, 'premio_usd' => 0.0];
            }

            return $this->manager->calcularPremioMultiSorteo(
                $apuesta->juego,
                [
                    'amount_bs' => (float) $apuesta->amount_bs,
                    'amount_usd' => (float) $apuesta->amount_usd,
                ],
                $acierto,
                $premiosSnapshot
            );
        }

        $resultados = $resultado ? $resultado->toArray() : [];

        return $this->manager->calcularPremio(
            $apuesta->juego,
            [
                'combinacion' => $combinacion,
                'amount_bs' => (float) $apuesta->amount_bs,
                'amount_usd' => (float) $apuesta->amount_usd,
            ],
            $resultados,
            $premiosSnapshot
        );
    }

    /**
     * ¿La combinación es una tripleta multi-sorteo (3+ selecciones)?
     *
     * @param  array<string, mixed>  $combinacion
     */
    public function esMultiSorteo(array $combinacion): bool
    {
        $selecciones = $combinacion['selecciones'] ?? [];

        return count($selecciones) >= 3
            && (($combinacion['modalidad'] ?? 'tripleta') === 'tripleta');
    }
}
