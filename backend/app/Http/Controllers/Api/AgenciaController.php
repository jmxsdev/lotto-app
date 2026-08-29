<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agencia;
use App\Models\Grupo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRUD de agencias (locales físicos) con alcance jerárquico por rol.
 *
 * super_master/master: todas; banca: agencias de su banca; grupo: agencias
 * de su grupo; agencia: solo su propio local (lectura). La agencia no
 * gestiona agencias (store/update/toggle/destroy → 403).
 */
class AgenciaController extends Controller
{
    /**
     * Listar agencias según el alcance del rol.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Agencia::query();

        if ($user->hasRole('super_master')) {
            // Sin filtro
        } elseif ($user->hasRole('master')) {
            // master ve solo los locales de sus bancas; sin bancas ve NADA
            $user->masterBancaGroupScope()($query);
        } elseif ($user->hasRole('banca')) {
            if (! $user->banca_id) {
                return response()->json(['message' => 'No tienes una banca asociada.'], 403);
            }
            $query->whereHas('grupo', fn ($q) => $q->where('banca_id', $user->banca_id));
        } elseif ($user->hasRole('grupo')) {
            if (! $user->grupo_id) {
                return response()->json(['message' => 'No tienes un grupo asociado.'], 403);
            }
            $query->where('grupo_id', $user->grupo_id);
        } elseif ($user->hasRole('agencia')) {
            if (! $user->agencia_id) {
                return response()->json(['message' => 'No tienes una agencia asociada.'], 403);
            }
            $query->whereKey($user->agencia_id);
        } else {
            return response()->json(['message' => 'No tienes permiso para ver agencias.'], 403);
        }

        return response()->json($query->with('grupo.banca')->orderBy('name')->get());
    }

    /**
     * Crear una agencia (local) dentro del alcance del rol.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $this->authorizeGestion($user);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:agencias,code',
            'grupo_id' => 'required|exists:grupos,id',
            'active' => 'boolean',
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
        ]);

        $this->authorizeGrupoAccess($user, $validated['grupo_id']);

        $agencia = Agencia::create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'grupo_id' => $validated['grupo_id'],
            'active' => $validated['active'] ?? true,
            'created_by' => $user->id,
            'rif' => $validated['rif'] ?? null,
            'email' => $validated['email'] ?? null,
            'telefono' => $validated['telefono'] ?? null,
            'direccion' => $validated['direccion'] ?? null,
            'estado' => $validated['estado'] ?? null,
            'municipio' => $validated['municipio'] ?? null,
        ]);

        return response()->json($agencia->load('grupo.banca'), 201);
    }

    /**
     * Mostrar una agencia dentro del alcance del rol.
     */
    public function show(Request $request, Agencia $agencia)
    {
        $this->authorizeAgenciaAccess($request->user(), $agencia);

        return response()->json($agencia->load('grupo.banca'));
    }

    /**
     * Actualizar una agencia dentro del alcance del rol.
     */
    public function update(Request $request, Agencia $agencia)
    {
        $user = $request->user();

        $this->authorizeGestion($user);
        $this->authorizeAgenciaAccess($user, $agencia);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', Rule::unique('agencias')->ignore($agencia->id)],
            'grupo_id' => 'sometimes|exists:grupos,id',
            'active' => 'boolean',
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
        ]);

        if (isset($validated['grupo_id'])) {
            $this->authorizeGrupoAccess($user, $validated['grupo_id']);
        }

        $agencia->update($validated);

        return response()->json($agencia->load('grupo.banca'));
    }

    /**
     * Alternar el estado activo de una agencia (local).
     */
    public function toggle(Request $request, Agencia $agencia)
    {
        $user = $request->user();

        $this->authorizeGestion($user);
        $this->authorizeAgenciaAccess($user, $agencia);

        $agencia->update(['active' => ! $agencia->active]);

        return response()->json($agencia->load('grupo.banca'));
    }

    /**
     * Eliminar (soft delete) un local con CASCADA (decisión del cliente tras
     * el WARNING de verify: la taquilla SIEMPRE tiene local asignado, nunca
     * se deja con agencia_id null):
     * - Taquillas del local: SOFT-DELETE (conservan agencia_id; el historial
     *   de apuestas/pagos/cierres queda intacto y trazable en reportes).
     * - Usuarios rol taquilla del local: se DESACTIVAN (active=false) y
     *   conservan agencia_id (User no usa SoftDeletes; se conserva el registro).
     * - El local se soft-deletea. Todo en una transacción.
     * Permisos intactos: authorizeGestion + authorizeAgenciaAccess ya acotan
     * quién puede borrar el local (roles sin permiso → 403).
     */
    public function destroy(Request $request, Agencia $agencia)
    {
        $user = $request->user();

        $this->authorizeGestion($user);
        $this->authorizeAgenciaAccess($user, $agencia);

        DB::transaction(function () use ($agencia) {
            // CASCADA: soft-delete de las taquillas del local (conservan agencia_id)
            $agencia->taquillas()->delete();

            // Usuarios rol taquilla del local: se desactivan (no se borran)
            $agencia->users()->where('role', 'taquilla')->update(['active' => false]);

            $agencia->delete();
        });

        return response()->json(['message' => 'Agencia eliminada correctamente.']);
    }

    // --- Autorización ---

    /**
     * La agencia (local) no gestiona agencias: solo lectura de su propio local.
     */
    private function authorizeGestion($user): void
    {
        if ($user->hasRole('agencia')) {
            abort(403, 'No tienes permisos para gestionar agencias.');
        }
    }

    private function authorizeGrupoAccess($user, int $grupoId): void
    {
        $grupo = Grupo::find($grupoId);
        if (! $grupo) {
            abort(404, 'Grupo no encontrado.');
        }

        if ($user->hasRole('super_master')) {
            return;
        }

        // Master solo accede a grupos de sus bancas
        if ($user->hasRole('master')) {
            if ($grupo->banca_id === null || ! $user->masterCanAccessBanca((int) $grupo->banca_id)) {
                abort(403, 'No tienes acceso a este grupo.');
            }

            return;
        }

        if ($user->hasRole('banca')) {
            if (! $user->banca_id || $user->banca_id != $grupo->banca_id) {
                abort(403, 'No tienes acceso a este grupo.');
            }

            return;
        }

        if ($user->hasRole('grupo')) {
            if (! $user->grupo_id || $user->grupo_id != $grupo->id) {
                abort(403, 'No tienes acceso a este grupo.');
            }

            return;
        }

        abort(403, 'No tienes permiso para acceder a este grupo.');
    }

    private function authorizeAgenciaAccess($user, Agencia $agencia): void
    {
        if ($user->hasRole('super_master')) {
            return;
        }

        // Master solo accede a locales de sus bancas
        if ($user->hasRole('master')) {
            $bancaId = $agencia->grupo?->banca_id;
            if ($bancaId === null || ! $user->masterCanAccessBanca((int) $bancaId)) {
                abort(403, 'No tienes acceso a esta agencia.');
            }

            return;
        }

        if ($user->hasRole('banca')) {
            if (! $user->banca_id || $user->banca_id != $agencia->grupo?->banca_id) {
                abort(403, 'No tienes acceso a esta agencia.');
            }

            return;
        }

        if ($user->hasRole('grupo')) {
            if (! $user->grupo_id || $user->grupo_id != $agencia->grupo_id) {
                abort(403, 'No tienes acceso a esta agencia.');
            }

            return;
        }

        if ($user->hasRole('agencia')) {
            if (! $user->agencia_id || $user->agencia_id != $agencia->id) {
                abort(403, 'No tienes acceso a esta agencia.');
            }

            return;
        }

        abort(403, 'No tienes acceso a esta agencia.');
    }
}
