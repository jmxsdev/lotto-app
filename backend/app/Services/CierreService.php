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
     * Ejecutar el cierre de caja de una taquilla (máquina).
     *
     * El período abarca [fecha_inicio, fecha_fin):
     *  - fecha_fin = now()
     *  - fecha_inicio = fecha_fin del último cierre de la taquilla, o la
     *    fecha_hora de su primera apuesta, o now() si no tiene actividad.
     *
     * Ventas: apuestas con estado != 'anulada' por fecha_hora.
     * Egresos: pagos tipo 'egreso' y 'devolucion' por created_at.
     * Efectivo por moneda = ventas - egresos.
     *
     * Arqueo opcional: si se indica el efectivo contado, se persiste y se
     * calcula la diferencia por moneda (faltante_sobrante_X = arqueo_X -
     * total_efectivo_X); si se omite, arqueo y diferencia quedan nulos.
     *
     * @throws \RuntimeException si no existe ninguna tasa de cambio
     */
    public function crearCierre(int $taquillaId, int $userId, ?float $arqueoBs = null, ?float $arqueoUsd = null): CierreCaja
    {
        return DB::transaction(function () use ($taquillaId, $userId, $arqueoBs, $arqueoUsd) {
            $tasa = $this->resolverTasa();

            $fechaFin = now();
            $fechaInicio = $this->resolveFechaInicio($taquillaId, $fechaFin);

            $totales = $this->calcularTotales($taquillaId, $fechaInicio, $fechaFin);

            return CierreCaja::create([
                'taquilla_id' => $taquillaId,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'total_ventas_bs' => $totales['total_ventas_bs'],
                'total_ventas_usd' => $totales['total_ventas_usd'],
                'total_ventas_bs_equivalent' => $totales['total_ventas_bs_equivalent'],
                'total_egresos_bs' => $totales['total_egresos_bs'],
                'total_egresos_usd' => $totales['total_egresos_usd'],
                'total_efectivo_bs' => $totales['total_efectivo_bs'],
                'total_efectivo_usd' => $totales['total_efectivo_usd'],
                'arqueo_efectivo_bs' => $arqueoBs,
                'arqueo_efectivo_usd' => $arqueoUsd,
                'faltante_sobrante_bs' => $arqueoBs !== null
                    ? round($arqueoBs - $totales['total_efectivo_bs'], 2)
                    : null,
                'faltante_sobrante_usd' => $arqueoUsd !== null
                    ? round($arqueoUsd - $totales['total_efectivo_usd'], 2)
                    : null,
                'desglose_metodos' => $totales['desglose_metodos'],
                'exchange_rate_cierre' => $tasa->rate,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Resolver la tasa de cambio del cierre (AD-6):
     * activa primero, si no hay activa se usa la última por reference_date
     * (fallback), y solo se lanza excepción si no existe ninguna tasa.
     *
     * @throws \RuntimeException si no hay tasa alguna (activa ni histórica)
     */
    private function resolverTasa(): ExchangeRate
    {
        $activa = ExchangeRate::where('is_active', true)->first();

        if ($activa) {
            return $activa;
        }

        $ultima = ExchangeRate::orderByDesc('reference_date')->orderByDesc('id')->first();

        if (! $ultima) {
            throw new \RuntimeException('No hay tasa de cambio activa para realizar el cierre.');
        }

        return $ultima;
    }

    /**
     * Calcular totales y desglose por método de pago del período.
     *
     * - Ventas: apuestas no anuladas (sin soft-delete) por fecha_hora;
     *   el desglose de ventas sale de los `pagos` ingreso de esas apuestas
     *   (JOIN por apuesta_id) agrupado por metodo_pago (AD-4).
     * - Egresos: pagos tipo egreso/devolucion por created_at, con desglose
     *   directo por metodo_pago.
     * - El USD se contabiliza íntegro bajo `efectivo` en el desglose.
     * - Neto por moneda = ventas - egresos.
     *
     * @return array<string, float|array>
     */
    private function calcularTotales(int $taquillaId, $fechaInicio, $fechaFin): array
    {
        $ventas = Apuesta::where('taquilla_id', $taquillaId)
            ->where('estado', '!=', 'anulada')
            ->where('fecha_hora', '>=', $fechaInicio)
            ->where('fecha_hora', '<', $fechaFin)
            ->selectRaw('SUM(amount_bs) as bs, SUM(amount_usd) as usd, SUM(total_bs_equivalent) as equivalente')
            ->first();

        $totalVentasBs = (float) ($ventas->bs ?? 0);
        $totalVentasUsd = (float) ($ventas->usd ?? 0);
        $totalVentasEquiv = (float) ($ventas->equivalente ?? 0);

        $ventasDesglose = Apuesta::where('apuestas.taquilla_id', $taquillaId)
            ->where('apuestas.estado', '!=', 'anulada')
            ->where('apuestas.fecha_hora', '>=', $fechaInicio)
            ->where('apuestas.fecha_hora', '<', $fechaFin)
            ->join('pagos', 'pagos.apuesta_id', '=', 'apuestas.id')
            ->where('pagos.tipo', 'ingreso')
            ->groupBy('pagos.metodo_pago')
            ->selectRaw('pagos.metodo_pago as metodo_pago, SUM(pagos.amount_bs) as bs, SUM(pagos.amount_usd) as usd')
            ->get();

        // Los ingresos (cobro de apuestas) no forman parte de los egresos
        $egresosQuery = Pago::where('taquilla_id', $taquillaId)
            ->whereIn('tipo', ['egreso', 'devolucion'])
            ->where('created_at', '>=', $fechaInicio)
            ->where('created_at', '<', $fechaFin);

        $egresos = (clone $egresosQuery)
            ->selectRaw('SUM(amount_bs) as bs, SUM(amount_usd) as usd')
            ->first();

        $egresosDesglose = (clone $egresosQuery)
            ->groupBy('metodo_pago')
            ->selectRaw('metodo_pago, SUM(amount_bs) as bs, SUM(amount_usd) as usd')
            ->get();

        $totalEgresosBs = (float) ($egresos->bs ?? 0);
        $totalEgresosUsd = (float) ($egresos->usd ?? 0);

        $desglose = $this->armarDesglose($ventasDesglose, $egresosDesglose);

        return [
            'total_ventas_bs' => round($totalVentasBs, 2),
            'total_ventas_usd' => round($totalVentasUsd, 2),
            'total_ventas_bs_equivalent' => round($totalVentasEquiv, 2),
            'total_egresos_bs' => round($totalEgresosBs, 2),
            'total_egresos_usd' => round($totalEgresosUsd, 2),
            'total_efectivo_bs' => round($totalVentasBs - $totalEgresosBs, 2),
            'total_efectivo_usd' => round($totalVentasUsd - $totalEgresosUsd, 2),
            'desglose_metodos' => $desglose,
        ];
    }

    /**
     * Armar el desglose {bs|usd: {metodo: {ventas, egresos, efectivo}}}.
     *
     * Shape fijo (AD-3): los 4 métodos siempre presentes por moneda con
     * ceros. El USD se normaliza íntegro bajo `efectivo` (AD-4): aunque un
     * pago USD se haya registrado con otro método, su monto cae en efectivo.
     */
    private function armarDesglose($ventasDesglose, $egresosDesglose): array
    {
        $desglose = [];
        foreach (['bs', 'usd'] as $moneda) {
            $desglose[$moneda] = [];
            foreach (Pago::METODOS_PAGO as $metodo) {
                $desglose[$moneda][$metodo] = ['ventas' => 0, 'egresos' => 0, 'efectivo' => 0];
            }
        }

        foreach ($ventasDesglose as $row) {
            $metodo = $row->metodo_pago ?? 'efectivo';
            $desglose['bs'][$metodo]['ventas'] += (float) $row->bs;
            $desglose['usd']['efectivo']['ventas'] += (float) $row->usd;
        }

        foreach ($egresosDesglose as $row) {
            $metodo = $row->metodo_pago ?? 'efectivo';
            $desglose['bs'][$metodo]['egresos'] += (float) $row->bs;
            $desglose['usd']['efectivo']['egresos'] += (float) $row->usd;
        }

        foreach (['bs', 'usd'] as $moneda) {
            foreach (Pago::METODOS_PAGO as $metodo) {
                $desglose[$moneda][$metodo]['efectivo'] = round(
                    $desglose[$moneda][$metodo]['ventas'] - $desglose[$moneda][$metodo]['egresos'],
                    2
                );
            }
        }

        return $desglose;
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
