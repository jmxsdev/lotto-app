<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agencia;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use App\Services\JuegoLimiteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

        if ($user->hasRole('super_master')) {
            // Sin filtro
        } elseif ($user->hasRole('master')) {
            // master ve solo las taquillas de sus bancas; sin bancas ve NADA
            $user->masterBancaGroupScope()($query);
        } elseif ($user->hasRole('banca')) {
            if (! $user->banca_id) {
                return response()->json(['message' => 'No tienes una banca asociada.'], 403);
            }
            // Filtrar por grupos que pertenecen a su banca
            $query->whereHas('grupo', function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id);
            });
        } elseif ($user->hasRole('grupo')) {
            if (! $user->grupo_id) {
                return response()->json(['message' => 'No tienes un grupo asociado.'], 403);
            }
            $query->where('grupo_id', $user->grupo_id);
        } elseif ($user->hasRole('agencia')) {
            if (! $user->agencia_id) {
                return response()->json(['message' => 'No tienes una agencia asociada.'], 403);
            }
            $query->where('agencia_id', $user->agencia_id);
        } else {
            return response()->json(['message' => 'No tienes permiso para ver taquillas.'], 403);
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

        $rules = [
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:taquillas,code',
            // Defensa en profundidad (espejo de la regla del update): la API
            // genera el código en servidor e ignora el del cliente, pero si un
            // cliente enviara uno, debe ser único (nunca 500 por colisión).
            'activation_code' => 'nullable|string|unique:taquillas,activation_code',
            'grupo_id' => ['required', Rule::exists('grupos', 'id')->whereNull('deleted_at')],
            // Decisión del cliente: una taquilla SIEMPRE tiene un local asignado.
            // El rol agencia lo deriva de su sesión (merge más abajo); el resto
            // de roles (super/master/grupo/banca) deben enviarlo.
            'agencia_id' => ['required', Rule::exists('agencias', 'id')->whereNull('deleted_at')],
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
            // Create mode: límites iniciales persistidos en la misma transacción
            'limites' => 'nullable|array',
        ];

        // La agencia (local) crea taquillas solo en su local: agencia_id y
        // grupo_id se derivan de su agencia (no editables; valores ajenos → 403).
        if ($user->hasRole('agencia')) {
            if (! $user->agencia_id) {
                return response()->json(['message' => 'No tienes una agencia asociada.'], 403);
            }
            $agencia = Agencia::find($user->agencia_id);
            if (! $agencia) {
                return response()->json(['message' => 'Agencia no encontrada.'], 403);
            }
            if ($request->filled('agencia_id') && (int) $request->agencia_id !== (int) $user->agencia_id) {
                return response()->json(['message' => 'No tienes acceso a esta agencia.'], 403);
            }
            if ($request->filled('grupo_id') && (int) $request->grupo_id !== (int) $agencia->grupo_id) {
                return response()->json(['message' => 'No tienes acceso a esta agencia.'], 403);
            }
            $request->merge([
                'agencia_id' => $agencia->id,
                'grupo_id' => $agencia->grupo_id,
            ]);
            $rules['grupo_id'] = ['nullable', Rule::exists('grupos', 'id')->whereNull('deleted_at')];
        }

        $request->validate($rules);

        // Verificar acceso al grupo
        $this->authorizeGrupoAccess($user, $request->grupo_id);

        // Verificar acceso al local (si se indicó)
        $this->authorizeAgenciaAccess($user, $request->input('agencia_id'));

        // El local debe pertenecer al grupo indicado (consistencia jerárquica)
        $this->validarLocalPerteneceAlGrupo($request->input('agencia_id'), (int) $request->grupo_id);

        $grupo = Grupo::find($request->grupo_id);

        // Invariante de jerarquía: el grupo debe tener una banca viva para
        // poder derivar la banca del usuario taquilla. Un grupo sin banca
        // (o con la banca soft-deleted) es inconsistente → 422, nunca 500.
        if ($grupo->banca === null) {
            abort(422, 'El grupo no tiene una banca válida.');
        }

        // Validar vigencia_premios contra el grupo (más restrictivo)
        $this->validarVigenciaContraParent($grupo, $request);

        // Validar tiempo_eliminacion contra el grupo/banca (más restrictivo: no puede alargar la ventana)
        $this->validarTiempoEliminacionContraParent($grupo, $request);

        // La API genera SIEMPRE el código de activación en servidor (P7):
        // cualquier valor enviado por el cliente se ignora. El panel enviaba
        // códigos hardcodeados que ya existían en prod → colisión de unique
        // (taquillas_activation_code_unique, UniqueConstraintViolationException).
        $activationCode = Str::random(16);

        $taquilla = null;
        $userCreado = null;

        DB::transaction(function () use ($request, $user, $grupo, $activationCode, &$taquilla, &$userCreado) {
            $taquilla = Taquilla::create([
                'name' => $request->name,
                'code' => $request->code,
                'grupo_id' => $request->grupo_id,
                'agencia_id' => $request->input('agencia_id'),
                'activation_code' => $activationCode,
                'vigencia_premios' => $request->vigencia_premios ?? null,
                'tiempo_eliminacion' => $request->tiempo_eliminacion ?? null,
                'active' => $request->active ?? false,
                'created_by' => $user->id,
                'rif' => $request->rif,
                'email' => $request->email,
                'telefono' => $request->telefono,
                'direccion' => $request->direccion,
                'estado' => $request->estado,
                'municipio' => $request->municipio,
            ]);

            $userCreado = User::create([
                'name' => $request->user_name,
                'email' => $request->user_email,
                'password' => Hash::make($request->user_password),
                'role' => 'taquilla',
                'banca_id' => $grupo->banca_id,
                'grupo_id' => $request->grupo_id,
                'taquilla_id' => $taquilla->id,
                'agencia_id' => $taquilla->agencia_id,
                'active' => $request->active ?? true,
            ]);

            $userCreado->assignRole('taquilla');

            // Límites iniciales (create mode): atómicos con la entidad.
            // Cualquier error de validación/restrictividad revierte TODO.
            if (! empty($request->limites)) {
                app(JuegoLimiteService::class)->validarItems($request->limites);
                app(JuegoLimiteService::class)->persistirParaEntidad($request->limites, 'taquilla', $taquilla->id);
            }
        });

        return response()->json([
            'taquilla' => $taquilla->load('grupo.banca'),
            'user' => $userCreado->load('roles'),
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
            'grupo_id' => ['sometimes', Rule::exists('grupos', 'id')->whereNull('deleted_at')],
            // Decisión del cliente: el local no se puede desasignar (si el campo
            // viene, debe ser un local real y no null); los PUT parciales de
            // otras pestañas (sin el campo) siguen siendo válidos.
            'agencia_id' => ['sometimes', 'required', Rule::exists('agencias', 'id')->whereNull('deleted_at')],
            'mac_address' => 'nullable|string',
            'activation_code' => 'nullable|string|unique:taquillas,activation_code,'.$taquilla->id,
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

        // Asignar/desasignar el local (agencia_id) con la misma autorización
        // jerárquica que el store (F1): el rol agencia solo su local.
        if ($request->has('agencia_id')) {
            $this->authorizeAgenciaAccess($user, $request->input('agencia_id'));

            // El local debe pertenecer al grupo efectivo (el indicado o el actual)
            $grupoEfectivo = $request->filled('grupo_id') ? (int) $request->grupo_id : (int) $taquilla->grupo_id;
            $this->validarLocalPerteneceAlGrupo($request->input('agencia_id'), $grupoEfectivo);
        }

        // Validar vigencia_premios contra el grupo padre
        if ($request->has('vigencia_premios')) {
            $grupo = $taquilla->grupo;
            $this->validarVigenciaContraParent($grupo, $request);
        }

        // Validar tiempo_eliminacion contra el grupo padre
        if ($request->has('tiempo_eliminacion')) {
            $grupo = $taquilla->grupo;
            $this->validarTiempoEliminacionContraParent($grupo, $request);
        }

        $data = $request->only(['name', 'code', 'grupo_id', 'mac_address', 'activation_code', 'active', 'vigencia_premios', 'tiempo_eliminacion', 'rif', 'email', 'telefono', 'direccion', 'estado', 'municipio']);

        // El local se asigna explícitamente; la validación (sometimes|required)
        // impide desasignarlo con null (decisión del cliente: la taquilla
        // siempre tiene local).
        if ($request->has('agencia_id')) {
            $data['agencia_id'] = $request->input('agencia_id');
        }

        // NULL = hereda del padre (columna nullable)
        $taquilla->update($data);

        return response()->json($taquilla->load('grupo.banca'));
    }

    /**
     * Alternar el estado activo de una taquilla (máquina).
     * PATCH /api/taquillas/{taquilla}/toggle
     * No desregistra el dispositivo: MAC y huella permanecen intactos,
     * por lo que al reactivar no se requiere re-activación.
     */
    public function toggle(Request $request, Taquilla $taquilla)
    {
        $user = auth()->user();

        $this->authorizeTaquillaAccess($user, $taquilla);

        $taquilla->update(['active' => ! $taquilla->active]);

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
            return response()->json(['message' => 'No se puede eliminar la taquilla porque tiene apuestas asociadas.'], 422);
        }

        $taquilla->delete();

        return response()->json(['message' => 'Taquilla eliminada correctamente.']);
    }

    // --- Métodos de autorización ---

    /**
     * Validar que el local (agencia_id) pertenezca al grupo indicado.
     * Un local de otro grupo es un estado inconsistente de la jerarquía.
     */
    private function validarLocalPerteneceAlGrupo(?int $agenciaId, int $grupoId): void
    {
        if ($agenciaId === null) {
            return;
        }

        $agencia = Agencia::find($agenciaId);
        if (! $agencia) {
            abort(422, 'El local no existe o fue eliminado.');
        }

        if ((int) $agencia->grupo_id !== $grupoId) {
            abort(422, 'El local no pertenece al grupo indicado.');
        }
    }

    /**
     * Validar que la vigencia_premios de la taquilla no exceda la del grupo.
     * El nivel hijo solo puede ser más restrictivo (menor o igual).
     */
    private function validarVigenciaContraParent(?Grupo $grupo, Request $request): void
    {
        // El grupo padre puede resolver null si fue soft-deleted (update).
        // Sin grupo no hay contra qué validar: error HTTP limpio, no TypeError.
        if ($grupo === null) {
            abort(422, 'El grupo asociado a la taquilla no existe o fue eliminado.');
        }

        if ($request->vigencia_premios === null) {
            return;
        }

        $grupoVigencia = $grupo->vigencia_premios;
        if ($grupoVigencia !== null && $request->vigencia_premios > $grupoVigencia) {
            abort(422, json_encode([
                'message' => "La vigencia de premios de la taquilla ({$request->vigencia_premios} días) no puede ser mayor que la del grupo ({$grupoVigencia} días). La jerarquía inferior solo puede acortar el plazo.",
            ]));
        }

        // También validar contra banca (si el grupo no tiene vigencia configurada)
        if ($grupoVigencia === null) {
            $bancaVigencia = $grupo->banca?->vigencia_premios;
            if ($bancaVigencia !== null && $request->vigencia_premios > $bancaVigencia) {
                abort(422, json_encode([
                    'message' => "La vigencia de premios de la taquilla ({$request->vigencia_premios} días) no puede ser mayor que la de la banca ({$bancaVigencia} días). La jerarquía inferior solo puede acortar el plazo.",
                ]));
            }
        }
    }

    /**
     * Validar que el tiempo_eliminacion de la taquilla no exceda el efectivo del grupo/banca.
     * El nivel hijo solo puede acortar la ventana (más restrictivo).
     */
    private function validarTiempoEliminacionContraParent(?Grupo $grupo, Request $request): void
    {
        // El grupo padre puede resolver null si fue soft-deleted (update).
        // Sin grupo no hay contra qué validar: error HTTP limpio, no TypeError.
        if ($grupo === null) {
            abort(422, 'El grupo asociado a la taquilla no existe o fue eliminado.');
        }

        if ($request->tiempo_eliminacion === null) {
            return;
        }

        $grupoTiempo = $grupo->tiempo_eliminacion;
        if ($grupoTiempo !== null && $request->tiempo_eliminacion > $grupoTiempo) {
            abort(422, "El tiempo máximo de la taquilla ({$request->tiempo_eliminacion} minutos) no puede ser mayor que el del grupo ({$grupoTiempo} minutos). La jerarquía inferior solo puede acortar el plazo.");
        }

        // También validar contra banca (si el grupo no tiene tiempo configurado)
        if ($grupoTiempo === null) {
            $bancaTiempo = $grupo->banca?->tiempo_eliminacion ?? 5;
            if ($request->tiempo_eliminacion > $bancaTiempo) {
                abort(422, "El tiempo máximo de la taquilla ({$request->tiempo_eliminacion} minutos) no puede ser mayor que el de la banca ({$bancaTiempo} minutos). La jerarquía inferior solo puede acortar el plazo.");
            }
        }
    }

    private function authorizeGrupoAccess($user, $grupoId)
    {
        $grupo = Grupo::find($grupoId);
        if (! $grupo) {
            abort(404, 'Grupo no encontrado.');
        }

        // Super Master puede todo
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

        // Agencia solo puede acceder al grupo de su local
        if ($user->hasRole('agencia')) {
            if (! $user->agencia_id || ! $grupo->agencias()->where('id', $user->agencia_id)->exists()) {
                abort(403, 'No tienes acceso a este grupo.');
            }

            return;
        }

        abort(403, 'No tienes permiso para acceder a este grupo.');
    }

    /**
     * Verificar que el local (agencia_id) esté dentro del alcance del rol.
     */
    private function authorizeAgenciaAccess($user, ?int $agenciaId)
    {
        if ($agenciaId === null) {
            return;
        }

        $agencia = Agencia::find($agenciaId);
        if (! $agencia) {
            abort(404, 'Agencia no encontrada.');
        }

        // Super Master puede todo
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

        // Banca solo accede a locales de su banca
        if ($user->hasRole('banca')) {
            if (! $user->banca_id || $user->banca_id != $agencia->grupo?->banca_id) {
                abort(403, 'No tienes acceso a esta agencia.');
            }

            return;
        }

        // Grupo solo accede a locales de su grupo
        if ($user->hasRole('grupo')) {
            if (! $user->grupo_id || $user->grupo_id != $agencia->grupo_id) {
                abort(403, 'No tienes acceso a esta agencia.');
            }

            return;
        }

        // Agencia solo accede a su propio local
        if ($user->hasRole('agencia')) {
            if (! $user->agencia_id || $user->agencia_id != $agencia->id) {
                abort(403, 'No tienes acceso a esta agencia.');
            }

            return;
        }

        abort(403, 'No tienes permiso para acceder a esta agencia.');
    }

    private function authorizeTaquillaAccess($user, Taquilla $taquilla)
    {
        // Super Master puede todo
        if ($user->hasRole('super_master')) {
            return;
        }

        // Master puede acceder a taquillas de sus bancas
        if ($user->hasRole('master')) {
            $bancaId = $taquilla->grupo?->banca_id;
            if ($bancaId === null || ! $user->masterCanAccessBanca((int) $bancaId)) {
                abort(403, 'No tienes acceso a esta taquilla.');
            }

            return;
        }

        // Banca puede acceder a taquillas de grupos de su banca
        if ($user->hasRole('banca')) {
            $grupo = $taquilla->grupo;
            if (! $user->banca_id || $user->banca_id != $grupo->banca_id) {
                abort(403, 'No tienes acceso a esta taquilla.');
            }

            return;
        }

        // Grupo puede acceder solo a sus taquillas
        if ($user->hasRole('grupo')) {
            if (! $user->grupo_id || $user->grupo_id != $taquilla->grupo_id) {
                abort(403, 'No tienes acceso a esta taquilla.');
            }

            return;
        }

        // Agencia puede acceder solo a las taquillas de su local
        if ($user->hasRole('agencia')) {
            if (! $user->agencia_id || $user->agencia_id != $taquilla->agencia_id) {
                abort(403, 'No tienes acceso a esta taquilla.');
            }

            return;
        }

        abort(403, 'No tienes permiso para acceder a esta taquilla.');
    }
}
