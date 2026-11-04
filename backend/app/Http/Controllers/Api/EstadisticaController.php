<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apuesta;
use App\Services\ApuestaService;
use App\Support\Scope\AlcanceService;
use Illuminate\Http\Request;

class EstadisticaController extends Controller
{
    protected ApuestaService $apuestaService;

    public function __construct(
        ApuestaService $apuestaService,
        private readonly AlcanceService $alcance,
    ) {
        $this->apuestaService = $apuestaService;
    }

    /**
     * Construir query base de apuestas con filtrado jerárquico por rol.
     *
     * Precedencia `APUESTAS`: taquilla → agencia → grupo → banca → master →
     * super_master. Fail-closed (REQ-4) incluido en el servicio: un rol
     * desconocido o ausente deniega con `No autorizado.` (403).
     */
    private function buildApuestaQuery(Request $request)
    {
        return $this->alcance->applyToApuestas(
            Apuesta::with(['juego', 'taquilla']),
            $request->user()
        );
    }

    /**
     * GET /api/estadisticas/rendimiento
     *
     * Series temporales diarias con 6 métricas: ventas, premios, pagados,
     * vencidos, devolucion, saldo.
     *
     * Filtros: fecha_desde, fecha_hasta, banca_id, tipo_juego, moneda.
     */
    public function rendimiento(Request $request)
    {
        $query = $this->buildApuestaQuery($request);

        // Filtros de fecha
        if ($request->has('fecha_desde')) {
            $query->whereDate('fecha_hora', '>=', $request->fecha_desde);
        }
        if ($request->has('fecha_hasta')) {
            $query->whereDate('fecha_hora', '<=', $request->fecha_hasta);
        }

        // Filtro por banca
        if ($request->has('banca_id')) {
            $query->whereHas('taquilla.grupo', function ($q) use ($request) {
                $q->where('banca_id', $request->banca_id);
            });
        }

        // Filtro por tipo de juego (slug)
        if ($request->has('tipo_juego')) {
            $query->whereHas('juego', function ($q) use ($request) {
                $q->where('slug', $request->tipo_juego);
            });
        }

        // Filtro por moneda
        if ($request->has('moneda') && in_array($request->moneda, ['bs', 'usd', 'mixto'])) {
            $query->where(function ($q) use ($request) {
                match ($request->moneda) {
                    'bs' => $q->where('apuestas.amount_bs', '>', 0)->where('apuestas.amount_usd', 0),
                    'usd' => $q->where('apuestas.amount_usd', '>', 0)->where('apuestas.amount_bs', 0),
                    'mixto' => $q->where('apuestas.amount_bs', '>', 0)->where('apuestas.amount_usd', '>', 0),
                    default => null,
                };
            });
        }

        $filters = [
            'fecha_desde' => $request->input('fecha_desde'),
            'fecha_hasta' => $request->input('fecha_hasta'),
            'banca_id' => $request->input('banca_id'),
            'tipo_juego' => $request->input('tipo_juego'),
            'moneda' => $request->input('moneda'),
        ];

        $data = $this->apuestaService->timeSeriesData($query, $filters);

        return response()->json($data);
    }
}
