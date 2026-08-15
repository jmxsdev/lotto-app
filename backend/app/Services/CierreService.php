<?php

namespace App\Services;

use App\Models\Apuesta;
use App\Models\CierreCaja;
use App\Models\ExchangeRate;
use App\Models\Pago;
use Illuminate\Support\Facades\DB;

class CierreService
{
    /**
     * Ejecutar el cierre de caja de una agencia.
     *
     * El período abarca [fecha_inicio, fecha_fin):
     *  - fecha_fin = now()
     *  - fecha_inicio = fecha_fin del último cierre de la agencia, o la
     *    fecha_hora de su primera apuesta, o now() si no tiene actividad.
     *
     * Ventas: apuestas con estado != 'anulada' por fecha_hora.
     * Egresos: pagos tipo 'egreso' y 'devolucion' por created_at.
     * Efectivo por moneda = ventas - egresos.
     *
     * @throws \RuntimeException si no hay tasa de cambio activa
     */
    public function crearCierre(int $taquillaId, int $userId): CierreCaja
    {
        return DB::transaction(function () use ($taquillaId, $userId) {
            $tasaActiva = ExchangeRate::where('is_active', true)->first();

            if (!$tasaActiva) {
                throw new \RuntimeException('No hay tasa de cambio activa para realizar el cierre.');
            }

            $fechaFin = now();
            $fechaInicio = $this->resolveFechaInicio($taquillaId, $fechaFin);

            $ventas = Apuesta::where('taquilla_id', $taquillaId)
                ->where('estado', '!=', 'anulada')
                ->where('fecha_hora', '>=', $fechaInicio)
                ->where('fecha_hora', '<', $fechaFin)
                ->selectRaw('SUM(amount_bs) as bs, SUM(amount_usd) as usd, SUM(total_bs_equivalent) as equivalente')
                ->first();

            $totalVentasBs = (float) ($ventas->bs ?? 0);
            $totalVentasUsd = (float) ($ventas->usd ?? 0);
            $totalVentasEquiv = (float) ($ventas->equivalente ?? 0);

            // Los ingresos (cobro de apuestas) no forman parte de los egresos
            $egresos = Pago::where('taquilla_id', $taquillaId)
                ->whereIn('tipo', ['egreso', 'devolucion'])
                ->where('created_at', '>=', $fechaInicio)
                ->where('created_at', '<', $fechaFin)
                ->selectRaw('SUM(amount_bs) as bs, SUM(amount_usd) as usd')
                ->first();

            $totalEgresosBs = (float) ($egresos->bs ?? 0);
            $totalEgresosUsd = (float) ($egresos->usd ?? 0);

            return CierreCaja::create([
                'taquilla_id' => $taquillaId,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'total_ventas_bs' => round($totalVentasBs, 2),
                'total_ventas_usd' => round($totalVentasUsd, 2),
                'total_ventas_bs_equivalent' => round($totalVentasEquiv, 2),
                'total_egresos_bs' => round($totalEgresosBs, 2),
                'total_egresos_usd' => round($totalEgresosUsd, 2),
                'total_efectivo_bs' => round($totalVentasBs - $totalEgresosBs, 2),
                'total_efectivo_usd' => round($totalVentasUsd - $totalEgresosUsd, 2),
                'exchange_rate_cierre' => $tasaActiva->rate,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Listar cierres de una consulta pre-escalada por jerarquía (controller).
     */
    public function listarCierres($query, int $perPage = 20)
    {
        return $query->with(['taquilla.grupo.banca', 'creador'])
            ->orderByDesc('fecha_fin')
            ->paginate($perPage);
    }

    /**
     * Resolver el inicio del período: último cierre → primera apuesta → ahora.
     */
    private function resolveFechaInicio(int $taquillaId, $fechaFin)
    {
        $ultimoCierre = CierreCaja::where('taquilla_id', $taquillaId)
            ->orderByDesc('fecha_fin')
            ->first();

        if ($ultimoCierre?->fecha_fin) {
            return $ultimoCierre->fecha_fin;
        }

        $primeraApuesta = Apuesta::where('taquilla_id', $taquillaId)
            ->orderBy('fecha_hora')
            ->value('fecha_hora');

        if ($primeraApuesta) {
            return $primeraApuesta;
        }

        return $fechaFin;
    }
}
