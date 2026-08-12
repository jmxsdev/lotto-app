<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apuesta;
use App\Services\ApuestaService;
use Illuminate\Http\Request;

class EstadisticaController extends Controller
{
    protected ApuestaService $apuestaService;

    public function __construct(ApuestaService $apuestaService)
    {
        $this->apuestaService = $apuestaService;
    }

    /**
     * Construir query base de apuestas con filtrado jerárquico por rol.
     */
    private function buildApuestaQuery(Request $request)
    {
        $user = $request->user();

        $query = Apuesta::with(['juego', 'taquilla']);

        // Filtrado jerárquico según rol
        if ($user->role === 'taquilla') {
            $query->where('taquilla_id', $user->taquilla_id);
        } elseif ($user->role === 'grupo') {
            $query->whereHas('taquilla.grupo', function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id);
            });
        } elseif ($user->role === 'banca') {
            $query->whereHas('taquilla.grupo.banca', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
        } elseif ($user->role === 'master') {
            // master ve todas las bancas que administra
        }
        // super_master ve todo (sin filtro adicional)

        return $query;
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
