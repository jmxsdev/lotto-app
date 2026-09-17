<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CierreCaja;
use App\Models\Taquilla;
use App\Services\CierreService;
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
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'arqueo_efectivo_bs' => 'nullable|numeric|min:0',
            'arqueo_efectivo_usd' => 'nullable|numeric|min:0',
        ]);

        $taquillaId = $this->resolveTaquillaParaCierre($user, $request);

        try {
            $cierre = $this->cierreService->crearCierre(
                $taquillaId,
                $user->id,
                isset($validated['arqueo_efectivo_bs']) ? (float) $validated['arqueo_efectivo_bs'] : null,
                isset($validated['arqueo_efectivo_usd']) ? (float) $validated['arqueo_efectivo_usd'] : null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($cierre->load('taquilla.grupo.banca'), 201);
    }

    /**
     * Listar cierres de caja con alcance jerárquico por rol.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = CierreCaja::query();

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

        return response()->json(
            $this->cierreService->listarCierres($query, (int) $request->input('per_page', 20))
        );
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
