<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApuestaStoreRequest;
use App\Models\Apuesta;
use App\Models\DetalleApuesta;
use App\Models\ExchangeRate;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\Log;
use App\Services\ApuestaService;
use App\Services\JuegoPluginManager;
use Illuminate\Http\Request;

class ApuestaController extends Controller
{
    protected ApuestaService $apuestaService;

    public function __construct(ApuestaService $apuestaService)
    {
        $this->apuestaService = $apuestaService;
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
        } elseif ($user->role === 'grupo') {
            $query->whereHas('taquilla.grupo', function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id);
            });
        } elseif ($user->role === 'banca') {
            $query->whereHas('taquilla.grupo.banca', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
        }
        // master y super_master ven todo

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
        $resumen = $this->apuestaService->obtenerResumen(
            $request->has('fecha_desde') || $request->has('fecha_hasta') || 
            $request->has('estado') || $request->has('juego_id') ? clone $query : Apuesta::query()
        );

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

        if (!$user->taquilla_id) {
            return response()->json([
                'message' => 'Solo las taquillas pueden crear apuestas.',
            ], 403);
        }

        $taquillaId = $user->taquilla_id;

        try {
            // Obtener tasa activa del momento
            $tasaActiva = ExchangeRate::where('is_active', true)->first();
            
            if (!$tasaActiva) {
                return response()->json([
                    'message' => 'No hay tasa de cambio activa configurada. Contacte al administrador.'
                ], 422);
            }

            // Calcular total_bs_equivalent
            $amountBs = (float) $request->amount_bs;
            $amountUsd = (float) $request->amount_usd;
            $totalBsEquivalent = $amountBs + ($amountUsd * $tasaActiva->rate);

            // Validar que cubre costo mínimo del juego
            $validacion = $this->apuestaService->validarCostoMinimo($totalBsEquivalent, $request->juego_id);
            
            if (!$validacion['valid']) {
                return response()->json([
                    'message' => $validacion['message'],
                    'costo_minimo' => $validacion['required_min'] ?? null,
                    'monto_actual' => round($totalBsEquivalent, 2),
                ], 422);
            }

            // Guardar apuesta con tasa histórica immutable
            $combinacion = $request->combinacion;

            $apuesta = Apuesta::create([
                'taquilla_id' => $taquillaId,
                'juego_id' => $request->juego_id,
                'resultado_id' => null,
                'combinacion' => json_encode($combinacion),
                'amount_bs' => $amountBs,
                'amount_usd' => $amountUsd,
                'exchange_rate_applied' => $tasaActiva->rate,
                'total_bs_equivalent' => $totalBsEquivalent,
                'estado' => 'pendiente',
                'fecha_hora' => now(),
                'sorteo_hora' => $request->sorteo_hora ?? $this->getNextDrawTime($request->juego_id),
            ]);

            // Generar detalles de la apuesta con plugin
            $juego = Juego::find($request->juego_id);
            $plugin = app(JuegoPluginManager::class)->getPlugin($juego);
            $premio = $plugin
                ? $plugin->calcularPremio(
                    ['combinacion' => $combinacion, 'total_bs_equivalent' => $totalBsEquivalent, 'amount_bs' => $amountBs, 'amount_usd' => $amountUsd],
                    []
                )
                : ['premio_bs' => $totalBsEquivalent, 'premio_usd' => 0];

            \App\Models\DetalleApuesta::create([
                'apuesta_id' => $apuesta->id,
                'combinacion' => json_encode($combinacion),
                'monto' => $totalBsEquivalent,
                'premio_posible' => $premio['premio_bs'],
                'premio_posible_usd' => $premio['premio_usd'],
                'premio_ganado' => null,
                'premio_ganado_usd' => null,
            ]);

            $moneda = $amountBs > 0 && $amountUsd > 0 ? 'mixto' : ($amountUsd > 0 ? 'usd' : 'bs');

            \App\Models\Pago::create([
                'taquilla_id' => $taquillaId,
                'apuesta_id' => $apuesta->id,
                'amount_bs' => $amountBs,
                'amount_usd' => $amountUsd,
                'exchange_rate_applied' => $tasaActiva->rate,
                'tipo' => 'ingreso',
                'moneda' => $moneda,
                'concepto' => 'Compra de ticket',
                'created_by' => $user->id,
            ]);

            return response()->json([
                'message' => 'Apuesta creada exitosamente.',
                'data' => $apuesta->load(['juego', 'taquilla']),
            ], 201);

        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage()
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
        } elseif ($user->role === 'grupo') {
            $query->whereHas('taquilla.grupo', function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id);
            });
        } elseif ($user->role === 'banca') {
            $query->whereHas('taquilla.grupo.banca', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
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
            $query->where('ticket_code', 'like', '%' . $request->ticket_code . '%');
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
        } elseif ($user->role === 'grupo') {
            $query->whereHas('taquilla.grupo', function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id);
            });
        } elseif ($user->role === 'banca') {
            $query->whereHas('taquilla.grupo.banca', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
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
     * Eliminar (soft delete) una apuesta dentro de la ventana de 5 minutos.
     */
    public function destroy(Request $request, Apuesta $apuesta)
    {
        $this->authorize('delete', $apuesta);

        $user = $request->user();
        $motivo = $request->input('motivo', 'Sin motivo especificado');

        $oldData = $apuesta->toArray();

        $apuesta->delete();

        Log::create([
            'user_id' => $user->id,
            'action' => 'apuesta.deleted',
            'details' => [
                'apuesta_id' => $apuesta->id,
                'ticket_code' => $apuesta->ticket_code,
                'motivo' => $motivo,
                'old_values' => $oldData,
            ],
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Apuesta anulada correctamente.',
        ]);
    }
}
