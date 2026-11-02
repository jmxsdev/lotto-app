<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TicketAnulacionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApuestaStoreRequest;
use App\Models\Apuesta;
use App\Models\JuegoHorario;
use App\Models\Pago;
use App\Services\ApuestaService;
use App\Services\TicketAnulacionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApuestaController extends Controller
{
    protected ApuestaService $apuestaService;

    protected TicketAnulacionService $ticketAnulacionService;

    public function __construct(
        ApuestaService $apuestaService,
        TicketAnulacionService $ticketAnulacionService
    ) {
        $this->apuestaService = $apuestaService;
        $this->ticketAnulacionService = $ticketAnulacionService;
    }

    /**
     * Listar apuestas con filtrado jerárquico por rol
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Apuesta::with(['juego', 'taquilla', 'resultado'])
            ->latest();

        // Filtrado jerárquico según rol del usuario
        if ($user->role === 'taquilla') {
            $query->where('taquilla_id', $user->taquilla_id);
        } elseif ($user->role === 'agencia') {
            $query->whereHas('taquilla', function ($q) use ($user) {
                $q->where('agencia_id', $user->agencia_id);
            });
        } elseif ($user->role === 'grupo') {
            $query->whereHas('taquilla.grupo', function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id);
            });
        } elseif ($user->role === 'banca') {
            $query->whereHas('taquilla.grupo.banca', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
        } elseif ($user->role === 'master') {
            // master ve solo las bancas que administra (y su descendencia);
            // sin bancas asignadas ve NADA (whereRaw 1=0, nunca global)
            $user->masterBancaChainScope()($query);
        }
        // super_master ve todo

        // Filtros opcionales
        if ($request->has('fecha_desde')) {
            $query->whereDate('fecha_hora', '>=', $request->fecha_desde);
        }
        if ($request->has('fecha_hasta')) {
            $query->whereDate('fecha_hora', '<=', $request->fecha_hasta);
        }
        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->has('juego_id')) {
            $query->where('juego_id', $request->juego_id);
        }
        if ($request->has('sorteo_hora')) {
            $query->whereDate('sorteo_hora', $request->sorteo_hora);
        }

        $apuestas = $query->paginate($request->input('per_page', 50));

        // Resumen estadístico
        $resumen = $this->apuestaService->obtenerResumen(clone $query);

        return response()->json([
            'data' => $apuestas,
            'summary' => $resumen,
        ]);
    }

    /**
     * Crear una nueva apuesta
     */
    protected function getNextDrawTime(int $juegoId): string
    {
        $horarios = JuegoHorario::where('juego_id', $juegoId)
            ->where('active', true)
            ->orderBy('hora')
            ->pluck('hora');

        if ($horarios->isEmpty()) {
            return now()->addHours(2)->format('Y-m-d H:i:s');
        }

        $now = now();
        foreach ($horarios as $hora) {
            $drawTime = $now->copy()->setTimeFromTimeString($hora);
            if ($drawTime->isFuture()) {
                return $drawTime->format('Y-m-d H:i:s');
            }
        }

        return $now->copy()->addDay()->setTimeFromTimeString($horarios->first())->format('Y-m-d H:i:s');
    }

    public function store(ApuestaStoreRequest $request)
    {
        $user = $request->user();

        if (! $user->taquilla_id) {
            return response()->json([
                'message' => 'Solo las taquillas pueden crear apuestas.',
            ], 403);
        }

        try {
            $apuesta = $this->apuestaService->createApuesta(
                $request->validated(),
                $user->taquilla_id,
                $user->id
            );

            return response()->json([
                'message' => 'Apuesta creada exitosamente.',
                'data' => $apuesta->load(['juego', 'taquilla']),
            ], 201);

        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error interno al procesar la apuesta.',
            ], 500);
        }
    }

    /**
     * Ver detalle de una apuesta/ticket
     */
    public function show(Apuesta $apuesta)
    {
        $this->authorize('view', $apuesta);

        return response()->json([
            'data' => $apuesta->load(['juego', 'taquilla', 'resultado', 'detalles', 'pago']),
        ]);
    }

    /**
     * Historial avanzado con filtros adicionales
     */
    public function historial(Request $request)
    {
        $user = $request->user();

        $query = Apuesta::with(['juego', 'taquilla', 'resultado'])
            ->latest();

        // Aplicar mismo filtrado jerárquico que index
        if ($user->role === 'taquilla') {
            $query->where('taquilla_id', $user->taquilla_id);
        } elseif ($user->role === 'agencia') {
            $query->whereHas('taquilla', function ($q) use ($user) {
                $q->where('agencia_id', $user->agencia_id);
            });
        } elseif ($user->role === 'grupo') {
            $query->whereHas('taquilla.grupo', function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id);
            });
        } elseif ($user->role === 'banca') {
            $query->whereHas('taquilla.grupo.banca', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
        } elseif ($user->role === 'master') {
            // master ve solo las bancas que administra (y su descendencia)
            $user->masterBancaChainScope()($query);
        }

        // Filtros avanzados
        if ($request->has('fecha_desde')) {
            $query->whereDate('fecha_hora', '>=', $request->fecha_desde);
        }
        if ($request->has('fecha_hasta')) {
            $query->whereDate('fecha_hora', '<=', $request->fecha_hasta);
        }
        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->has('juego_id')) {
            $query->where('juego_id', $request->juego_id);
        }
        if ($request->has('sorteo_hora')) {
            $query->whereDate('sorteo_hora', $request->sorteo_hora);
        }
        if ($request->has('ticket_code')) {
            $query->where('ticket_code', 'like', '%'.$request->ticket_code.'%');
        }

        $apuestas = $query->paginate($request->input('per_page', 100));

        $resumen = $this->apuestaService->obtenerResumen(clone $query);

        return response()->json([
            'data' => $apuestas,
            'summary' => $resumen,
        ]);
    }

    /**
     * Resumen estadístico general
     */
    public function resumen(Request $request)
    {
        $user = $request->user();

        $query = Apuesta::query();

        // Aplicar filtrado jerárquico
        if ($user->role === 'taquilla') {
            $query->where('taquilla_id', $user->taquilla_id);
        } elseif ($user->role === 'agencia') {
            $query->whereHas('taquilla', function ($q) use ($user) {
                $q->where('agencia_id', $user->agencia_id);
            });
        } elseif ($user->role === 'grupo') {
            $query->whereHas('taquilla.grupo', function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id);
            });
        } elseif ($user->role === 'banca') {
            $query->whereHas('taquilla.grupo.banca', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
        } elseif ($user->role === 'master') {
            // master ve solo las bancas que administra (y su descendencia)
            $user->masterBancaChainScope()($query);
        }

        // Filtros temporales
        if ($request->has('fecha_desde') && $request->has('fecha_hasta')) {
            $query->whereBetween('fecha_hora', [$request->fecha_desde, $request->fecha_hasta]);
        }

        $resumen = $this->apuestaService->obtenerResumen($query);

        // Agregar datos de tasa actual
        $resumen['tasa_actual'] = $this->apuestaService->getTasaActiva();

        return response()->json(['data' => $resumen]);
    }

    /**
     * Anular una venta suelta pre-sorteo y registrar su devolución (WU3-B).
     *
     * `DELETE /apuestas` ya NO soft-deletea a secas: la regla de negocio
     * (estado, `resultado_id`, sorteo, ventana efectiva, ownership) vive en
     * `ApuestaPolicy`, y la transición + la devolución las ejecuta
     * `TicketAnulacionService::anularApuestaSuelta`.
     *
     * El `authorize('view')` va primero por diseño (§7.3): un caller ajeno
     * recibe 403 antes del 422 de "esto es una línea de ticket", de modo que
     * una URL de línea nunca filtra si pertenece a un ticket ni permite mutar
     * un ticket que no posee.
     */
    public function destroy(Request $request, Apuesta $apuesta)
    {
        try {
            $this->authorize('view', $apuesta);
        } catch (AuthorizationException $e) {
            // La excepción se convierte a AccessDeniedHttpException antes de
            // los render callbacks globales; se normaliza aquí para mantener
            // el contrato JSON "No autorizado." de la API.
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $request->validate([
            'metodo_pago' => ['nullable', Rule::in(Pago::METODOS_PAGO)],
            'referencia' => 'nullable|string|max:255',
            'motivo' => 'nullable|string|max:255',
        ]);

        try {
            $pago = $this->ticketAnulacionService->anularApuestaSuelta(
                $apuesta,
                $request->user(),
                [
                    'metodo_pago' => $request->input('metodo_pago'),
                    'referencia' => $request->input('referencia'),
                    'motivo' => $request->input('motivo'),
                    'ip' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                ]
            );
        } catch (TicketAnulacionException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Apuesta anulada correctamente.',
            'data' => [
                'devolucion' => [
                    'id' => $pago->id,
                    'pago_id' => $pago->id,
                    'apuesta_id' => $pago->apuesta_id,
                    'amount_bs' => (string) $pago->amount_bs,
                    'amount_usd' => (string) $pago->amount_usd,
                    'exchange_rate_applied' => (string) $pago->exchange_rate_applied,
                    'metodo_pago' => $pago->metodo_pago,
                    'moneda' => $pago->moneda,
                ],
            ],
        ]);
    }
}
