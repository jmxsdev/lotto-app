<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banca;
use App\Models\Grupo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            if (! $user->banca_id) {
                return response()->json(['message' => 'No tienes una banca asociada.'], 403);
            }
            $query->where('banca_id', $user->banca_id);
        }
        // Grupo: ve solo su grupo (aunque no debería ver grupos, pero por si acaso)
        elseif ($user->hasRole('grupo')) {
            if (! $user->grupo_id) {
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
            'user_name' => 'required|string|max:255',
            'user_email' => 'required|email|unique:users,email',
            'user_password' => 'required|string|min:8',
            'monedas_permitidas' => 'sometimes|array',
            'monedas_permitidas.bs' => 'boolean',
            'monedas_permitidas.usd' => 'boolean',
            'vigencia_premios' => 'nullable|integer|min:1',
            'tiempo_eliminacion' => 'nullable|integer|min:1|max:120',
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
        ]);

        // Verificar que el usuario tenga acceso a la banca
        $this->authorizeBancaAccess($user, $request->banca_id);

        // Validar monedas_permitidas contra la banca
        if ($request->has('monedas_permitidas')) {
            $banca = Banca::find($request->banca_id);
            $bancaMonedas = $banca?->monedas_permitidas;
            if (! $this->validarMonedasContraParent($bancaMonedas, $request->monedas_permitidas)) {
                return response()->json([
                    'message' => 'El grupo no puede habilitar una moneda que la banca ha deshabilitado. La jerarquía inferior solo puede restringir, no expandir.',
                ], 422);
            }
        }

        // Validar vigencia_premios contra la banca (más restrictivo)
        if ($request->has('vigencia_premios') && $request->vigencia_premios !== null) {
            $banca = Banca::find($request->banca_id);
            $bancaVigencia = $banca?->vigencia_premios;
            if ($bancaVigencia !== null && $request->vigencia_premios > $bancaVigencia) {
                return response()->json([
                    'message' => 'La vigencia de premios del grupo no puede ser mayor que la de la banca ('.$bancaVigencia.' días).',
                ], 422);
            }
        }

        // Validar tiempo_eliminacion contra la banca (más restrictivo: no puede alargar la ventana)
        if ($request->has('tiempo_eliminacion') && $request->tiempo_eliminacion !== null) {
            $banca = Banca::find($request->banca_id);
            $bancaTiempo = $banca?->tiempo_eliminacion ?? 5;
            if ($request->tiempo_eliminacion > $bancaTiempo) {
                return response()->json([
                    'message' => "El tiempo máximo del grupo no puede ser mayor que el de la banca ({$bancaTiempo} minutos). La jerarquía inferior solo puede acortar el plazo.",
                ], 422);
            }
        }

        $grupo = Grupo::create([
            'name' => $request->name,
            'code' => $request->code,
            'banca_id' => $request->banca_id,
            'active' => $request->active ?? true,
            'monedas_permitidas' => $request->monedas_permitidas ?? null,
            'vigencia_premios' => $request->vigencia_premios ?? null,
            'tiempo_eliminacion' => $request->tiempo_eliminacion ?? null,
            'created_by' => $user->id,
            'rif' => $request->rif,
            'email' => $request->email,
            'telefono' => $request->telefono,
            'direccion' => $request->direccion,
            'estado' => $request->estado,
            'municipio' => $request->municipio,
        ]);

        $user = User::create([
            'name' => $request->user_name,
            'email' => $request->user_email,
            'password' => Hash::make($request->user_password),
            'role' => 'grupo',
            'banca_id' => $request->banca_id,
            'grupo_id' => $grupo->id,
            'active' => $request->active ?? true,
        ]);

        $user->assignRole('grupo');

        return response()->json([
            'grupo' => $grupo->load('banca'),
            'user' => $user->load('roles'),
        ], 201);
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
            'monedas_permitidas' => 'sometimes|array',
            'monedas_permitidas.bs' => 'boolean',
            'monedas_permitidas.usd' => 'boolean',
            'vigencia_premios' => 'nullable|integer|min:1',
            'tiempo_eliminacion' => 'nullable|integer|min:1|max:120',
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
        ]);

        if ($request->has('banca_id')) {
            $this->authorizeBancaAccess($user, $request->banca_id);
        }

        // Validar monedas_permitidas contra la banca
        if ($request->has('monedas_permitidas')) {
            $bancaMonedas = $grupo->banca?->monedas_permitidas;
            if (! $this->validarMonedasContraParent($bancaMonedas, $request->monedas_permitidas)) {
                return response()->json([
                    'message' => 'El grupo no puede habilitar una moneda que la banca ha deshabilitado. La jerarquía inferior solo puede restringir, no expandir.',
                ], 422);
            }
        }

        // Validar vigencia_premios contra la banca (más restrictivo)
        if ($request->has('vigencia_premios')) {
            $bancaVigencia = $grupo->banca?->vigencia_premios;
            if ($bancaVigencia !== null && $request->vigencia_premios !== null) {
                if ($request->vigencia_premios > $bancaVigencia) {
                    return response()->json([
                        'message' => 'La vigencia de premios del grupo no puede ser mayor que la de la banca ('.$bancaVigencia.' días).',
                    ], 422);
                }
            }
        }

        // Validar tiempo_eliminacion contra la banca (más restrictivo: no puede alargar la ventana)
        if ($request->has('tiempo_eliminacion') && $request->tiempo_eliminacion !== null) {
            $bancaTiempo = $grupo->banca?->tiempo_eliminacion ?? 5;
            if ($request->tiempo_eliminacion > $bancaTiempo) {
                return response()->json([
                    'message' => "El tiempo máximo del grupo no puede ser mayor que el de la banca ({$bancaTiempo} minutos). La jerarquía inferior solo puede acortar el plazo.",
                ], 422);
            }
        }

        $grupo->update($request->only(['name', 'code', 'banca_id', 'active', 'monedas_permitidas', 'vigencia_premios', 'tiempo_eliminacion', 'rif', 'email', 'telefono', 'direccion', 'estado', 'municipio']));

        return response()->json($grupo->load('banca'));
    }

    /**
     * Alternar el estado activo de un grupo.
     * PATCH /api/grupos/{grupo}/toggle
     * No propaga cambios a las agencias hijas: el activo efectivo se resuelve en runtime.
     */
    public function toggle(Request $request, Grupo $grupo)
    {
        $user = auth()->user();

        $this->authorizeGrupoAccess($user, $grupo);

        $grupo->update(['active' => ! $grupo->active]);

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
            return response()->json(['message' => 'No se puede eliminar el grupo porque tiene agencias asociadas.'], 422);
        }

        $grupo->delete();

        return response()->json(['message' => 'Grupo eliminado correctamente.']);
    }

    // --- Métodos de validación ---

    /**
     * Validar que las monedas del grupo no habiliten una moneda
     * que la banca tiene deshabilitada.
     */
    private function validarMonedasContraParent(?array $bancaMonedas, array $grupoMonedas): bool
    {
        $bancaBs = $bancaMonedas['bs'] ?? true;
        $bancaUsd = $bancaMonedas['usd'] ?? true;
        $grupoBs = $grupoMonedas['bs'] ?? true;
        $grupoUsd = $grupoMonedas['usd'] ?? true;

        // Si la banca tiene BS explícitamente deshabilitado, el grupo no puede habilitarlo
        if (! $bancaBs && $grupoBs) {
            return false;
        }

        // Si la banca tiene USD explícitamente deshabilitado, el grupo no puede habilitarlo
        if (! $bancaUsd && $grupoUsd) {
            return false;
        }

        return true;
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
            if (! $user->banca_id || $user->banca_id != $bancaId) {
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
            if (! $user->banca_id || $user->banca_id != $grupo->banca_id) {
                abort(403, 'No tienes acceso a este grupo.');
            }

            return;
        }

        // Grupo solo puede acceder a su propio grupo
        if ($user->hasRole('grupo')) {
            if (! $user->grupo_id || $user->grupo_id != $grupo->id) {
                abort(403, 'No tienes acceso a este grupo.');
            }

            return;
        }

        abort(403, 'No tienes permiso para acceder a este grupo.');
    }
}
