<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apuesta;
use App\Models\Ticket;
use App\Services\ApuestaService;
use Illuminate\Http\Request;

class ReporteController extends Controller
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
            // master ve todas las bancas que administra (mismo alcance que super_master en reportes)
        }
        // super_master ve todo (sin filtro adicional)

        // Filtros de fecha
        if ($request->has('fecha_desde')) {
            $query->whereDate('fecha_hora', '>=', $request->fecha_desde);
        }
        if ($request->has('fecha_hasta')) {
            $query->whereDate('fecha_hora', '<=', $request->fecha_hasta);
        }

        return $query;
    }

    /**
     * Construir query base de tickets con filtrado jerárquico por rol.
     */
    private function buildTicketQuery(Request $request)
    {
        $user = $request->user();

        $query = Ticket::with(['taquilla', 'apuestas']);

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

        // Filtros de fecha
        if ($request->has('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }
        if ($request->has('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }

        return $query;
    }

    /**
     * GET /api/reportes/ventas-totales
     *
     * Ventas totales agregadas por banca.
     * Filtros: fecha_desde, fecha_hasta, tipo_juego (slug), moneda (bs/usd/mixto).
     */
    public function ventasTotales(Request $request)
    {
        $query = $this->buildApuestaQuery($request);

        $filters = [
            'tipo_juego' => $request->input('tipo_juego'),
            'moneda' => $request->input('moneda'),
            'nivel' => $request->input('nivel', 'banca'), // banca, grupo, taquilla
        ];

        $data = $this->apuestaService->ventasTotales($query, $filters);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * GET /api/reportes/cuadre-caja
     *
     * Cuadre de caja por entidad con las columnas Entidad, Venta, Pagados,
     * Devoluciones, Vencidos, Efectivo, PesoVenta y Participacion.
     * Filtros: fecha_desde, fecha_hasta, nivel (banca|grupo|agencia),
     * moneda (bs/usd/mixto), tipo_juego (slug).
     */
    public function cuadreCaja(Request $request)
    {
        $query = $this->buildApuestaQuery($request);

        $filters = [
            'fecha_desde' => $request->input('fecha_desde'),
            'fecha_hasta' => $request->input('fecha_hasta'),
            'nivel' => $request->input('nivel', 'banca'), // banca, grupo, agencia
            'moneda' => $request->input('moneda'),
            'tipo_juego' => $request->input('tipo_juego'),
        ];

        $resultado = $this->apuestaService->cuadreCaja($query, $filters);

        return response()->json([
            'data' => $resultado['data'],
            'totales' => $resultado['totales'],
        ]);
    }

    /**
     * GET /api/reportes/relacion-tickets
     *
     * Lista de tickets con columnas computadas.
     * Filtros: fecha (date), moneda.
     */
    public function relacionTickets(Request $request)
    {
        $query = $this->buildTicketQuery($request);

        // Filtro por fecha específica (un solo día)
        if ($request->has('fecha')) {
            $query->whereDate('created_at', $request->fecha);
        }

        // Filtro por moneda a nivel de ticket
        if ($request->has('moneda') && in_array($request->moneda, ['bs', 'usd', 'mixto'])) {
            $query->where(function ($q) use ($request) {
                match ($request->moneda) {
                    'bs' => $q->where('total_bs', '>', 0)->where('total_usd', 0),
                    'usd' => $q->where('total_usd', '>', 0)->where('total_bs', 0),
                    'mixto' => $q->where('total_bs', '>', 0)->where('total_usd', '>', 0),
                    default => null,
                };
            });
        }

        $filters = [
            'fecha' => $request->input('fecha'),
            'moneda' => $request->input('moneda'),
        ];

        $paginator = $this->apuestaService->relacionTickets(
            $query,
            $filters,
            $request->input('per_page', 50)
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/reportes/rendimiento-taquillas
     *
     * Rendimiento por taquilla con métricas de venta, anulación, premio y ganancia.
     */
    public function rendimientoTaquillas(Request $request)
    {
        $query = $this->buildApuestaQuery($request);

        $filters = [];
        $data = $this->apuestaService->rendimientoTaquillas($query, $filters);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * GET /api/reportes/vencidos
     *
     * Lista de tickets expirados (estado = 'vencido').
     * Filtros: fecha_desde, fecha_hasta.
     */
    public function vencidos(Request $request)
    {
        $query = $this->buildTicketQuery($request);

        // Solo tickets con estado 'vencido'
        $query->where('estado', 'vencido');

        $tickets = $query->with(['taquilla.grupo.banca', 'apuestas'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 50));

        // Enriquecer con detalles
        $tickets->through(function ($ticket) {
            $ticket->Ticket_N = $ticket->ticket_code;
            $ticket->Monto = (float) ($ticket->total_bs + $ticket->total_usd);
            $ticket->Premio = (float) ($ticket->premio_total_bs + $ticket->premio_total_usd);
            $ticket->Banca = $ticket->taquilla?->grupo?->banca?->name;
            $ticket->Grupo = $ticket->taquilla?->grupo?->name;
            $ticket->Agencia = $ticket->taquilla?->name;
            $ticket->Fecha = $ticket->created_at?->format('Y-m-d');
            $ticket->Jugadas = $ticket->apuestas?->count() ?? 0;

            return $ticket;
        });

        return response()->json([
            'data' => $tickets->items(),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }
}
