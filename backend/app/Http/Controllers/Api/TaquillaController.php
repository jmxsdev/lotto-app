<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Taquilla;
use App\Models\Grupo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class TaquillaController extends Controller
{
   /* public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:view_taquillas|manage_taquillas')->only(['index', 'show']);
        $this->middleware('permission:manage_taquillas')->only(['store', 'update', 'destroy']);
    }
    */
    /**
     * Listar taquillas (filtradas según jerarquía)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Taquilla::query();

        if ($user->hasRole(['super_master', 'master'])) {
            // Sin filtro
        } elseif ($user->hasRole('banca')) {
            if (!$user->banca_id) {
                return response()->json(['message' => 'No tienes una banca asociada.'], 403);
            }
            // Filtrar por grupos que pertenecen a su banca
            $query->whereHas('grupo', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
        } elseif ($user->hasRole('grupo')) {
            if (!$user->grupo_id) {
                return response()->json(['message' => 'No tienes un grupo asociado.'], 403);
            }
            $query->where('grupo_id', $user->grupo_id);
        } else {
            return response()->json(['message' => 'No tienes permiso para ver agencias.'], 403);
        }

        $taquillas = $query->with('grupo.banca')->get();

        return response()->json($taquillas);
    }

    /**
     * Crear una nueva taquilla
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:taquillas,code',
            'grupo_id' => 'required|exists:grupos,id',
            'active' => 'boolean',
            'vigencia_premios' => 'nullable|integer|min:1',
            'tiempo_eliminacion' => 'nullable|integer|min:1|max:120',
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
            'user_name' => 'required|string|max:255',
            'user_email' => 'required|email|unique:users,email',
            'user_password' => 'required|string|min:8',
        ]);

        // Verificar acceso al grupo
        $this->authorizeGrupoAccess($user, $request->grupo_id);

        $grupo = Grupo::find($request->grupo_id);

        // Validar vigencia_premios contra el grupo (más restrictivo)
        $this->validarVigenciaContraParent($grupo, $request);

        // Generar activation_code automáticamente si no se proporciona
        $activationCode = $request->activation_code ?? Str::random(16);

        $taquilla = Taquilla::create([
            'name' => $request->name,
            'code' => $request->code,
            'grupo_id' => $request->grupo_id,
            'activation_code' => $activationCode,
            'vigencia_premios' => $request->vigencia_premios ?? null,
            'tiempo_eliminacion' => $request->tiempo_eliminacion ?? 5,
            'active' => $request->active ?? false,
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
            'role' => 'taquilla',
            'banca_id' => $grupo->banca_id,
            'grupo_id' => $request->grupo_id,
            'taquilla_id' => $taquilla->id,
            'active' => $request->active ?? true,
        ]);

        $user->assignRole('taquilla');

        return response()->json([
            'taquilla' => $taquilla->load('grupo.banca'),
            'user' => $user->load('roles'),
        ], 201);
    }

    /**
     * Mostrar una taquilla específica
     */
    public function show(Taquilla $taquilla)
    {
        $user = auth()->user();

        $this->authorizeTaquillaAccess($user, $taquilla);

        return response()->json($taquilla->load('grupo.banca'));
    }

    /**
     * Actualizar una taquilla
     */
    public function update(Request $request, Taquilla $taquilla)
    {
        $user = auth()->user();

        $this->authorizeTaquillaAccess($user, $taquilla);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', Rule::unique('taquillas')->ignore($taquilla->id)],
            'grupo_id' => 'sometimes|exists:grupos,id',
            'mac_address' => 'nullable|string',
            'activation_code' => 'nullable|string|unique:taquillas,activation_code,' . $taquilla->id,
            'active' => 'boolean',
            'vigencia_premios' => 'nullable|integer|min:1',
            'tiempo_eliminacion' => 'nullable|integer|min:1|max:120',
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
        ]);

        if ($request->has('grupo_id')) {
            $this->authorizeGrupoAccess($user, $request->grupo_id);
        }

        // Validar vigencia_premios contra el grupo padre
        if ($request->has('vigencia_premios')) {
            $grupo = $taquilla->grupo;
            $this->validarVigenciaContraParent($grupo, $request);
        }

        $data = $request->only(['name', 'code', 'grupo_id', 'mac_address', 'activation_code', 'active', 'vigencia_premios', 'tiempo_eliminacion', 'rif', 'email', 'telefono', 'direccion', 'estado', 'municipio']);

        // La columna es NOT NULL con default 5: ignorar null explícito
        if (array_key_exists('tiempo_eliminacion', $data) && $data['tiempo_eliminacion'] === null) {
            unset($data['tiempo_eliminacion']);
        }

        $taquilla->update($data);

        return response()->json($taquilla->load('grupo.banca'));
    }

    /**
     * Eliminar una taquilla
     */
    public function destroy(Taquilla $taquilla)
    {
        $user = auth()->user();

        $this->authorizeTaquillaAccess($user, $taquilla);

        // Verificar que no tenga apuestas (opcional)
        if ($taquilla->apuestas()->count() > 0) {
            return response()->json(['message' => 'No se puede eliminar la agencia porque tiene apuestas asociadas.'], 422);
        }

        $taquilla->delete();

        return response()->json(['message' => 'Agencia eliminada correctamente.']);
    }

    // --- Métodos de autorización ---

    /**
     * Validar que la vigencia_premios de la taquilla no exceda la del grupo.
     * El nivel hijo solo puede ser más restrictivo (menor o igual).
     */
    private function validarVigenciaContraParent(Grupo $grupo, Request $request): void
    {
        if ($request->vigencia_premios === null) {
            return;
        }

        $grupoVigencia = $grupo->vigencia_premios;
        if ($grupoVigencia !== null && $request->vigencia_premios > $grupoVigencia) {
            abort(422, json_encode([
                'message' => "La vigencia de premios de la agencia ({$request->vigencia_premios} días) no puede ser mayor que la del grupo ({$grupoVigencia} días). La jerarquía inferior solo puede acortar el plazo."
            ]));
        }

        // También validar contra banca (si el grupo no tiene vigencia configurada)
        if ($grupoVigencia === null) {
            $bancaVigencia = $grupo->banca?->vigencia_premios;
            if ($bancaVigencia !== null && $request->vigencia_premios > $bancaVigencia) {
                abort(422, json_encode([
                    'message' => "La vigencia de premios de la agencia ({$request->vigencia_premios} días) no puede ser mayor que la de la banca ({$bancaVigencia} días). La jerarquía inferior solo puede acortar el plazo."
                ]));
            }
        }
    }

    private function authorizeGrupoAccess($user, $grupoId)
    {
        $grupo = Grupo::find($grupoId);
        if (!$grupo) {
            abort(404, 'Grupo no encontrado.');
        }

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

    private function authorizeTaquillaAccess($user, Taquilla $taquilla)
    {
        // Super Master y Master pueden todo
        if ($user->hasRole(['super_master', 'master'])) {
            return;
        }

        // Banca puede acceder a taquillas de grupos de su banca
        if ($user->hasRole('banca')) {
            $grupo = $taquilla->grupo;
            if (!$user->banca_id || $user->banca_id != $grupo->banca_id) {
                abort(403, 'No tienes acceso a esta agencia.');
            }
            return;
        }

        // Grupo puede acceder solo a sus taquillas
        if ($user->hasRole('grupo')) {
            if (!$user->grupo_id || $user->grupo_id != $taquilla->grupo_id) {
                abort(403, 'No tienes acceso a esta agencia.');
            }
            return;
        }

        abort(403, 'No tienes permiso para acceder a esta agencia.');
    }
}
