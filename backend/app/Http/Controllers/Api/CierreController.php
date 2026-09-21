<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CierreCaja;
use App\Models\Taquilla;
use App\Services\CierreService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CierreController extends Controller
{
    private CierreService $cierreService;

    public function __construct(CierreService $cierreService)
    {
        $this->cierreService = $cierreService;
    }

    /**
     * Ejecutar el cierre de caja de una taquilla (máquina).
     *
     * La taquilla (rol taquilla) cierra su propia caja; los roles
     * administrativos deben indicar la taquilla (taquilla_id) dentro de
     * su alcance jerárquico.
     *
     * Un cierre por día (AD-2/AD-4): el primer cierre responde 201 con
     * reclosed=false; si ya existe el cierre del día, exige clave_cierre
     * (nullable|digits_between:4,8) y responde 200 con reclosed=true.
     * Los errores de la clave (ausente, incorrecta, sin candidatos) y de
     * la tasa se mapean a 422 con el mensaje claro del servicio.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'arqueo_efectivo_bs' => 'nullable|numeric|min:0',
            'arqueo_efectivo_usd' => 'nullable|numeric|min:0',
            'clave_cierre' => 'nullable|digits_between:4,8',
        ]);

        $taquillaId = $this->resolveTaquillaParaCierre($user, $request);

        try {
            $resultado = $this->cierreService->crearCierre(
                $taquillaId,
                $user->id,
                isset($validated['arqueo_efectivo_bs']) ? (float) $validated['arqueo_efectivo_bs'] : null,
                isset($validated['arqueo_efectivo_usd']) ? (float) $validated['arqueo_efectivo_usd'] : null,
                $validated['clave_cierre'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $cierre = $resultado['cierre']->load('taquilla.grupo.banca');

        return response()->json(
            $cierre->toArray() + ['reclosed' => $resultado['reclosed']],
            $resultado['reclosed'] ? 200 : 201
        );
    }

    /**
     * Listar cierres de caja con alcance jerárquico por rol.
     */
    public function index(Request $request)
    {
        $query = CierreCaja::query();

        if (($error = $this->scopeCierresPara($request->user(), $query)) !== null) {
            return $error;
        }

        return response()->json(
            $this->cierreService->listarCierres($query, (int) $request->input('per_page', 20))
        );
    }

    /**
     * Preview read-only del período actual de una taquilla (AD-8):
     * misma autorización que store, no persiste nada.
     */
    public function actual(Request $request)
    {
        $user = $request->user();

        $taquillaId = $this->resolveTaquillaParaCierre($user, $request);

        try {
            $preview = $this->cierreService->previsualizar($taquillaId);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($preview);
    }

    /**
     * Rollup semanal de los diarios persistidos (AD-5), con el mismo
     * alcance jerárquico que index. `taquilla_id` es opcional para roles
     * administrativos (filtra su alcance; fuera de él → 403); el rol
     * taquilla siempre consulta su propia taquilla.
     */
    public function semanal(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'fecha' => 'nullable|date',
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
        ]);

        $query = CierreCaja::query();

        if (($error = $this->scopeCierresPara($user, $query)) !== null) {
            return $error;
        }

        if ($request->filled('taquilla_id')) {
            $taquilla = Taquilla::find((int) $request->input('taquilla_id'));

            if (! $taquilla) {
                return response()->json(['message' => 'Taquilla no encontrada.'], 404);
            }

            $this->assertTaquillaEnAlcance($user, $taquilla);
            $query->where('taquilla_id', $taquilla->id);
        }

        $ventana = $this->resolveVentanaSemanal($request);

        $reporte = $this->cierreService->reporteSemanal($query, $ventana['desde'], $ventana['hasta']);

        return response()->json([
            'fecha_desde' => $ventana['fecha_desde'],
            'fecha_hasta' => $ventana['fecha_hasta'],
        ] + $reporte);
    }

    /**
     * Ver el detalle de un cierre dentro del alcance del rol.
     */
    public function show(Request $request, CierreCaja $cierre)
    {
        $this->authorizeCierreAccess($request->user(), $cierre);

        return response()->json($cierre->load('taquilla.grupo.banca', 'creador'));
    }

    // --- Métodos de autorización ---

    /**
     * Acotar la consulta de cierres al alcance jerárquico del rol
     * (misma lógica que index).
     *
     * @return JsonResponse|null respuesta 403, o null si el alcance se aplicó
     */
    private function scopeCierresPara($user, $query): ?JsonResponse
    {
        if ($user->hasRole('super_master')) {
            // Ve todos
        } elseif ($user->hasRole('master')) {
            // master ve solo las bancas que administra (y su descendencia);
            // sin bancas asignadas ve NADA (whereRaw 1=0, nunca global)
            $user->masterBancaChainScope()($query);
        } elseif ($user->hasRole('banca')) {
            if (! $user->banca_id) {
                return response()->json(['message' => 'No tienes una banca asociada.'], 403);
            }
            $query->whereHas('taquilla.grupo', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
        } elseif ($user->hasRole('grupo')) {
            if (! $user->grupo_id) {
                return response()->json(['message' => 'No tienes un grupo asociado.'], 403);
            }
            $query->whereHas('taquilla', function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id);
            });
        } elseif ($user->hasRole('agencia')) {
            if (! $user->agencia_id) {
                return response()->json(['message' => 'No tienes una agencia asociada.'], 403);
            }
            $query->whereHas('taquilla', function ($q) use ($user) {
                $q->where('agencia_id', $user->agencia_id);
            });
        } elseif ($user->hasRole('taquilla')) {
            if (! $user->taquilla_id) {
                return response()->json(['message' => 'No tienes una taquilla asociada.'], 403);
            }
            $query->where('taquilla_id', $user->taquilla_id);
        } else {
            return response()->json(['message' => 'No tienes permisos para ver cierres de caja.'], 403);
        }

        return null;
    }

    /**
     * Verificar que una taquilla esté dentro del alcance jerárquico del rol
     * (violación → 403).
     */
    private function assertTaquillaEnAlcance($user, Taquilla $taquilla): void
    {
        if ($user->hasRole('taquilla')) {
            if (! $user->taquilla_id || (int) $user->taquilla_id !== (int) $taquilla->id) {
                abort(403, 'No tienes acceso a la caja de esta taquilla.');
            }

            return;
        }

        if ($user->hasRole('super_master')) {
            return;
        }

        if ($user->hasRole('master')) {
            $bancaId = $taquilla->grupo?->banca_id;
            if ($bancaId === null || ! $user->masterCanAccessBanca((int) $bancaId)) {
                abort(403, 'No tienes acceso a la caja de esta taquilla.');
            }

            return;
        }

        if ($user->hasRole('agencia')) {
            if (! $user->agencia_id || $user->agencia_id != $taquilla->agencia_id) {
                abort(403, 'No tienes acceso a la caja de esta taquilla.');
            }

            return;
        }

        if ($user->hasRole('banca')) {
            if (! $user->banca_id || $user->banca_id != $taquilla->grupo?->banca_id) {
                abort(403, 'No tienes acceso a la caja de esta taquilla.');
            }

            return;
        }

        if ($user->hasRole('grupo')) {
            if (! $user->grupo_id || $user->grupo_id != $taquilla->grupo_id) {
                abort(403, 'No tienes acceso a la caja de esta taquilla.');
            }

            return;
        }

        abort(403, 'No tienes acceso a la caja de esta taquilla.');
    }

    /**
     * Resolver la ventana semanal (AD-5):
     * - `fecha` (ancla): semana calendario lunes–domingo en America/Caracas.
     * - `fecha_desde` + `fecha_hasta`: rango ad-hoc, ambos inclusivos a nivel
     *   de día; internamente [desde, fecha_hasta + 1 día).
     * Ambos modos son mutuamente excluyentes (XOR); default: hoy.
     *
     * @return array{desde: Carbon, hasta: Carbon, fecha_desde: string, fecha_hasta: string}
     */
    private function resolveVentanaSemanal(Request $request): array
    {
        $fecha = $request->input('fecha');
        $desde = $request->input('fecha_desde');
        $hasta = $request->input('fecha_hasta');

        $tieneRango = $desde !== null || $hasta !== null;

        if ($tieneRango) {
            if ($desde === null || $hasta === null) {
                abort(422, 'fecha_desde y fecha_hasta deben enviarse juntos.');
            }

            if ($fecha !== null) {
                abort(422, 'Los parámetros fecha y fecha_desde/fecha_hasta son mutuamente excluyentes.');
            }

            $inicio = Carbon::parse($desde)->startOfDay();
            $fin = Carbon::parse($hasta)->startOfDay()->addDay();

            return [
                'desde' => $inicio,
                'hasta' => $fin,
                'fecha_desde' => $inicio->toDateString(),
                'fecha_hasta' => $fin->copy()->subDay()->toDateString(),
            ];
        }

        $ancla = $fecha !== null ? Carbon::parse($fecha) : now();
        $inicio = $ancla->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $fin = $inicio->copy()->addDays(7);

        return [
            'desde' => $inicio,
            'hasta' => $fin,
            'fecha_desde' => $inicio->toDateString(),
            'fecha_hasta' => $fin->copy()->subDay()->toDateString(),
        ];
    }

    /**
     * Resolver la taquilla a cerrar según el rol del usuario.
     *
     * @return int taquilla_id autorizada
     */
    private function resolveTaquillaParaCierre($user, Request $request): int
    {
        // La taquilla (rol taquilla) cierra su propia caja
        if ($user->hasRole('taquilla')) {
            if (! $user->taquilla_id) {
                abort(403, 'No tienes una taquilla asociada.');
            }

            if ($request->filled('taquilla_id') && (int) $request->taquilla_id !== (int) $user->taquilla_id) {
                abort(403, 'Solo puedes cerrar la caja de tu propia taquilla.');
            }

            return (int) $user->taquilla_id;
        }

        // Roles administrativos: deben indicar la taquilla a cerrar
        $validated = $request->validate([
            'taquilla_id' => 'required|integer|exists:taquillas,id',
        ]);

        $taquilla = Taquilla::find($validated['taquilla_id']);

        if ($user->hasRole('super_master')) {
            return $taquilla->id;
        }

        // El master solo cierra cajas de taquillas de sus bancas
        if ($user->hasRole('master')) {
            $bancaId = $taquilla->grupo?->banca_id;
            if ($bancaId === null || ! $user->masterCanAccessBanca((int) $bancaId)) {
                abort(403, 'No tienes acceso a la caja de esta taquilla.');
            }

            return $taquilla->id;
        }

        // La agencia (local) puede cerrar la caja de cualquiera de sus taquillas
        if ($user->hasRole('agencia')) {
            if (! $user->agencia_id || $user->agencia_id != $taquilla->agencia_id) {
                abort(403, 'No tienes acceso a la caja de esta taquilla.');
            }

            return $taquilla->id;
        }

        if ($user->hasRole('banca')) {
            if (! $user->banca_id || $user->banca_id != $taquilla->grupo?->banca_id) {
                abort(403, 'No tienes acceso a la caja de esta taquilla.');
            }

            return $taquilla->id;
        }

        if ($user->hasRole('grupo')) {
            if (! $user->grupo_id || $user->grupo_id != $taquilla->grupo_id) {
                abort(403, 'No tienes acceso a la caja de esta taquilla.');
            }

            return $taquilla->id;
        }

        abort(403, 'No tienes permiso para ejecutar cierres de caja.');
    }

    /**
     * Verificar acceso jerárquico a un cierre.
     */
    private function authorizeCierreAccess($user, CierreCaja $cierre): void
    {
        if ($user->hasRole('super_master')) {
            return;
        }

        if ($user->hasRole('master')) {
            $bancaId = $cierre->taquilla?->grupo?->banca_id;
            if ($bancaId === null || ! $user->masterCanAccessBanca((int) $bancaId)) {
                abort(403, 'No tienes acceso a este cierre.');
            }

            return;
        }

        if ($user->hasRole('banca')) {
            if (! $user->banca_id || $user->banca_id != $cierre->taquilla?->grupo?->banca_id) {
                abort(403, 'No tienes acceso a este cierre.');
            }

            return;
        }

        if ($user->hasRole('grupo')) {
            if (! $user->grupo_id || $user->grupo_id != $cierre->taquilla?->grupo_id) {
                abort(403, 'No tienes acceso a este cierre.');
            }

            return;
        }

        if ($user->hasRole('agencia')) {
            if (! $user->agencia_id || $user->agencia_id != $cierre->taquilla?->agencia_id) {
                abort(403, 'No tienes acceso a este cierre.');
            }

            return;
        }

        if ($user->hasRole('taquilla')) {
            if (! $user->taquilla_id || $user->taquilla_id != $cierre->taquilla_id) {
                abort(403, 'No tienes acceso a este cierre.');
            }

            return;
        }

        abort(403, 'No tienes acceso a este cierre.');
    }
}
