<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grupo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GrupoController extends Controller
{
   /* public function __construct()
    {
        // Aplicar middleware de autenticación y permisos
        $this->middleware('auth:sanctum');
        $this->middleware('permission:view_grupos|manage_grupos')->only(['index', 'show']);
        $this->middleware('permission:manage_grupos')->only(['store', 'update', 'destroy']);
    }*/

    /**
     * Listar grupos (filtrados según jerarquía)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Grupo::query();

        // Super Master y Master: ven todos
        if ($user->hasRole(['super_master', 'master'])) {
            // Sin filtro adicional
        }
        // Banca: ve solo sus grupos
        elseif ($user->hasRole('banca')) {
            if (!$user->banca_id) {
                return response()->json(['message' => 'No tienes una banca asociada.'], 403);
            }
            $query->where('banca_id', $user->banca_id);
        }
        // Grupo: ve solo su grupo (aunque no debería ver grupos, pero por si acaso)
        elseif ($user->hasRole('grupo')) {
            if (!$user->grupo_id) {
                return response()->json(['message' => 'No tienes un grupo asociado.'], 403);
            }
            $query->where('id', $user->grupo_id);
        }
        // Otros roles no pueden ver grupos
        else {
            return response()->json(['message' => 'No tienes permiso para ver grupos.'], 403);
        }

        $grupos = $query->with('banca')->get();

        return response()->json($grupos);
    }

    /**
     * Crear un nuevo grupo
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:grupos,code',
            'banca_id' => 'required|exists:bancas,id',
            'active' => 'boolean',
        ]);

        // Verificar que el usuario tenga acceso a la banca
        $this->authorizeBancaAccess($user, $request->banca_id);

        $grupo = Grupo::create([
            'name' => $request->name,
            'code' => $request->code,
            'banca_id' => $request->banca_id,
            'active' => $request->active ?? true,
        ]);

        return response()->json($grupo->load('banca'), 201);
    }

    /**
     * Mostrar un grupo específico
     */
    public function show(Grupo $grupo)
    {
        $user = auth()->user();

        // Verificar acceso jerárquico
        $this->authorizeGrupoAccess($user, $grupo);

        return response()->json($grupo->load('banca', 'taquillas'));
    }

    /**
     * Actualizar un grupo
     */
    public function update(Request $request, Grupo $grupo)
    {
        $user = auth()->user();

        // Verificar acceso
        $this->authorizeGrupoAccess($user, $grupo);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', Rule::unique('grupos')->ignore($grupo->id)],
            'banca_id' => 'sometimes|exists:bancas,id',
            'active' => 'boolean',
        ]);

        if ($request->has('banca_id')) {
            $this->authorizeBancaAccess($user, $request->banca_id);
        }

        $grupo->update($request->only(['name', 'code', 'banca_id', 'active']));

        return response()->json($grupo->load('banca'));
    }

    /**
     * Eliminar un grupo
     */
    public function destroy(Grupo $grupo)
    {
        $user = auth()->user();

        $this->authorizeGrupoAccess($user, $grupo);

        // Verificar que no tenga taquillas asociadas (opcional)
        if ($grupo->taquillas()->count() > 0) {
            return response()->json(['message' => 'No se puede eliminar el grupo porque tiene taquillas asociadas.'], 422);
        }

        $grupo->delete();

        return response()->json(['message' => 'Grupo eliminado correctamente.']);
    }

    // --- Métodos de autorización ---

    private function authorizeBancaAccess($user, $bancaId)
    {
        // Super Master y Master pueden cualquier banca
        if ($user->hasRole(['super_master', 'master'])) {
            return;
        }

        // Banca solo puede gestionar su propia banca
        if ($user->hasRole('banca')) {
            if (!$user->banca_id || $user->banca_id != $bancaId) {
                abort(403, 'No tienes acceso a esta banca.');
            }
            return;
        }

        // Otros roles no pueden
        abort(403, 'No tienes permiso para gestionar esta banca.');
    }

    private function authorizeGrupoAccess($user, Grupo $grupo)
    {
        // Super Master y Master pueden todo
        if ($user->hasRole(['super_master', 'master'])) {
            return;
        }

        // Banca solo puede acceder a grupos de su banca
        if ($user->hasRole('banca')) {
            if (!$user->banca_id || $user->banca_id != $grupo->banca_id) {
                abort(403, 'No tienes acceso a este grupo.');
            }
            return;
        }

        // Grupo solo puede acceder a su propio grupo
        if ($user->hasRole('grupo')) {
            if (!$user->grupo_id || $user->grupo_id != $grupo->id) {
                abort(403, 'No tienes acceso a este grupo.');
            }
            return;
        }

        abort(403, 'No tienes permiso para acceder a este grupo.');
    }
}
