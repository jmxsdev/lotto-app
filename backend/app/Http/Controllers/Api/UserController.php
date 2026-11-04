<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agencia;
use App\Models\Grupo;
use App\Models\Log;
use App\Models\Taquilla;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Listar usuarios (filtrados según jerarquía y opcionalmente por entidad).
     * GET /api/users?banca_id=&grupo_id=&taquilla_id=
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $filtros = $request->validate([
            'banca_id' => 'nullable|integer|exists:bancas,id',
            'grupo_id' => 'nullable|integer|exists:grupos,id',
            'taquilla_id' => 'nullable|integer|exists:taquillas,id',
            'agencia_id' => 'nullable|integer|exists:agencias,id',
            'role' => ['nullable', Rule::in(['super_master', 'master', 'banca', 'grupo', 'agencia', 'taquilla'])],
        ]);

        $query = User::query();

        // Super Master ve todos
        if ($user->hasRole('super_master')) {
            // Sin filtro
        }
        // Master ve solo los usuarios de las bancas que administra (y su
        // descendencia por cadena) más los que creó; sin bancas asignadas
        // conserva únicamente a sus creados.
        elseif ($user->hasRole('master')) {
            $masterIds = $user->masterBancaIds();
            $query->where(function ($q) use ($user, $masterIds) {
                if ($masterIds->isEmpty()) {
                    $q->where('created_by', $user->id);
                } else {
                    $q->whereIn('banca_id', $masterIds)
                        ->orWhereHas('taquilla', fn ($t) => $t->whereHas('grupo', fn ($g) => $g->whereIn('banca_id', $masterIds)))
                        ->orWhere('created_by', $user->id);
                }
            });
        }
        // Banca ve los usuarios de su banca (por vínculo directo o por cadena)
        // más los que creó.
        elseif ($user->hasRole('banca')) {
            if (! $user->banca_id) {
                return response()->json(['message' => 'No tienes una banca asociada.'], 403);
            }
            $query->where(function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id)
                    ->orWhereHas('taquilla', fn ($t) => $t->whereHas('grupo', fn ($g) => $g->where('banca_id', $user->banca_id)))
                    ->orWhere('created_by', $user->id);
            });
        }
        // Grupo ve los usuarios de su grupo, de sus agencias y los que creó.
        elseif ($user->hasRole('grupo')) {
            if (! $user->grupo_id) {
                return response()->json(['message' => 'No tienes un grupo asociado.'], 403);
            }
            $query->where(function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id)
                    ->orWhereHas('taquilla', fn ($t) => $t->where('grupo_id', $user->grupo_id))
                    ->orWhere('created_by', $user->id);
            });
        }
        // Agencia ve los usuarios de su local y los que creó.
        elseif ($user->hasRole('agencia')) {
            if (! $user->agencia_id) {
                return response()->json(['message' => 'No tienes una agencia asociada.'], 403);
            }
            $query->where(function ($q) use ($user) {
                $q->where('agencia_id', $user->agencia_id)
                    ->orWhere('created_by', $user->id);
            });
        }
        // Taquilla solo se ve a sí misma
        elseif ($user->hasRole('taquilla')) {
            $query->where('id', $user->id);
        }
        // Otros roles no pueden listar usuarios
        else {
            return response()->json(['message' => 'No tienes permisos para ver usuarios.'], 403);
        }

        // Filtros explícitos por entidad (validados previamente).
        // Se aplican DESPUÉS del alcance jerárquico: intersectan, nunca amplían.
        // Un filtro fuera del alcance del rol devuelve lista vacía (no hay fuga de datos).
        if (isset($filtros['banca_id'])) {
            $query->where('banca_id', $filtros['banca_id']);
        }

        if (isset($filtros['grupo_id'])) {
            $query->where('grupo_id', $filtros['grupo_id']);
        }

        if (isset($filtros['agencia_id'])) {
            $query->where('agencia_id', $filtros['agencia_id']);
        }

        if (isset($filtros['taquilla_id'])) {
            $query->where('taquilla_id', $filtros['taquilla_id']);
        }

        // Filtro por rol: intersección con el alcance jerárquico (nunca amplía).
        // Un master que filtre role=master solo vería masters con banca_id dentro
        // de sus bancas, que no existen (los masters no pertenecen a una banca) → vacío.
        if (isset($filtros['role'])) {
            $query->role($filtros['role']);
        }

        $users = $query->with('banca', 'grupo', 'taquilla', 'roles', 'bancas')->get();

        return response()->json($users);
    }

    /**
     * Crear un nuevo usuario.
     * Acepta vínculos de entidad (banca_id/grupo_id/taquilla_id) con
     * autorización jerárquica y derivación automática de la cadena.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'user_name' => 'required|string|max:255',
            'user_email' => 'required|email|unique:users,email',
            'user_password' => 'required|string|min:8',
            'role' => ['required', Rule::in(['super_master', 'master', 'banca', 'grupo', 'agencia', 'taquilla'])],
            'active' => 'boolean',
            'banca_id' => 'nullable|exists:bancas,id',
            'grupo_id' => 'nullable|exists:grupos,id',
            'agencia_id' => 'nullable|exists:agencias,id',
            'taquilla_id' => 'nullable|exists:taquillas,id',
        ]);

        // Derivar la cadena de vínculos (taquilla → grupo → banca)
        $bindings = $this->deriveEntityBindings($request);

        $data = [
            'created_by' => $user->id,
            'name' => $request->user_name,
            'email' => $request->user_email,
            'password' => Hash::make($request->user_password),
            'active' => $request->active ?? true,
        ] + $bindings;

        // Consistencia rol ↔ vínculo de entidad
        $this->validateRoleBindings($request->role, $data);

        // Verificar que el usuario autenticado pueda asignar este rol y vínculos
        $this->authorizeEntityBinding($user, $data, $request->role);

        $newUser = DB::transaction(function () use ($request, $data) {
            $newUser = User::create($data);
            $newUser->syncRoles([$request->role]);

            return $newUser;
        });

        return response()->json($newUser->load('roles'), 201);
    }

    /**
     * Mostrar un usuario específico
     */
    public function show(User $user)
    {
        $this->authorize('view', $user);

        return response()->json($user->load('banca', 'grupo', 'taquilla', 'roles'));
    }

    /**
     * Actualizar un usuario
     */
    public function update(Request $request, User $user)
    {
        $currentUser = auth()->user();

        $this->authorize('update', $user);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$user->id,
            'password' => 'sometimes|string|min:8',
            'role' => ['sometimes', Rule::in(['super_master', 'master', 'banca', 'grupo', 'agencia', 'taquilla'])],
            'active' => 'boolean',
            'banca_id' => 'nullable|exists:bancas,id',
            'grupo_id' => 'nullable|exists:grupos,id',
            'agencia_id' => 'nullable|exists:agencias,id',
            'taquilla_id' => 'nullable|exists:taquillas,id',
        ]);

        $data = $request->only(['name', 'email', 'active']);

        // Derivar los nuevos vínculos de entidad si se enviaron
        $bindings = $this->deriveEntityBindings($request);
        if ($bindings !== []) {
            $data = array_merge($data, $bindings);
        }

        $finalRole = $request->input('role', $user->primaryRole());

        // Sin rol efectivo (ni en el request ni en Spatie) no se puede validar
        // ni autorizar la operación: fail-closed.
        if ($finalRole === null) {
            abort(403, 'El usuario no tiene un rol asignado.');
        }

        $roleChanging = $request->filled('role') && ! $user->hasRole($request->role);

        // Al cambiar rol o vínculos: validar consistencia y autorización
        // jerárquica. `canAssign` impide autopromoción, pares y mover al
        // usuario fuera del subárbol del actor.
        if ($request->filled('role') || $bindings !== []) {
            $this->validateRoleBindings($finalRole, $data, $user);
        }

        if ($roleChanging || $bindings !== []) {
            $this->authorizeEntityBinding($currentUser, $data, $finalRole, $user);
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        // Snapshot del set completo de roles antes de persistir: permite
        // auditar también el colapso de un usuario multi-rol.
        $before = $user->getRoleNames()->sort()->values()->all();
        $cambioRol = $request->filled('role') && $before !== [$request->role];

        DB::transaction(function () use ($request, $currentUser, $user, $data, $cambioRol, $before) {
            $user->update($data);

            if ($request->filled('role')) {
                $user->syncRoles([$request->role]);
            }

            if ($cambioRol) {
                Log::create([
                    'user_id' => $currentUser->id, // actor
                    'action' => 'user.role_changed',
                    'details' => [
                        'actor_id' => $currentUser->id,
                        'target_id' => $user->id,
                        'target_email' => $user->email,
                        'before' => $before,
                        'after' => $user->getRoleNames()->sort()->values()->all(),
                    ],
                    'ip' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                ]);
            }
        });

        return response()->json($user->load('roles'));
    }

    /**
     * Eliminar un usuario (super_master global; master/banca/grupo/agencia
     * solo dentro de su subárbol o sobre sus creados, siempre de rango menor).
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        $user->delete();

        return response()->json(['message' => 'Usuario eliminado correctamente.']);
    }

    // --- Métodos de autorización ---

    /**
     * Autorizar la creación/asignación de un usuario con un rol y unos
     * vínculos de entidad, delegando en `UserPolicy`.
     *
     * `canCreate` cubre el alta; `canAssign` cubre la edición de un usuario
     * existente (cambio de rol o de vínculos).
     */
    private function authorizeEntityBinding($currentUser, array $data, string $role, ?User $existing = null)
    {
        $policy = app(UserPolicy::class);

        $allowed = $existing === null
            ? $policy->canCreate($currentUser, $role, $data)
            : $policy->canAssign($currentUser, $role, $data, $existing);

        if (! $allowed) {
            abort(403, 'No tienes permisos para gestionar usuarios con ese rol o vínculo.');
        }
    }

    // --- Helpers de vínculos ---

    /**
     * Deriva la cadena de vínculos a partir de lo enviado:
     * taquilla_id → agencia_id + banca_id + grupo_id;
     * agencia_id → banca_id + grupo_id; grupo_id → banca_id.
     * Limpia los vínculos inferiores cuando se vincula a un nivel superior.
     */
    private function deriveEntityBindings(Request $request): array
    {
        if ($request->filled('taquilla_id')) {
            $taquilla = Taquilla::find($request->taquilla_id);

            return [
                'taquilla_id' => $taquilla->id,
                'agencia_id' => $taquilla->agencia_id,
                'grupo_id' => $taquilla->grupo_id,
                'banca_id' => $taquilla->grupo->banca_id,
            ];
        }

        if ($request->filled('agencia_id')) {
            $agencia = Agencia::with('grupo')->find($request->agencia_id);

            return [
                'taquilla_id' => null,
                'agencia_id' => $agencia->id,
                'grupo_id' => $agencia->grupo_id,
                'banca_id' => $agencia->grupo?->banca_id,
            ];
        }

        if ($request->filled('grupo_id')) {
            $grupo = Grupo::find($request->grupo_id);

            return [
                'taquilla_id' => null,
                'agencia_id' => null,
                'grupo_id' => $grupo->id,
                'banca_id' => $grupo->banca_id,
            ];
        }

        if ($request->filled('banca_id')) {
            return [
                'taquilla_id' => null,
                'agencia_id' => null,
                'grupo_id' => null,
                'banca_id' => $request->banca_id,
            ];
        }

        return [];
    }

    /**
     * Valida que el rol requiera (y tenga) el vínculo de entidad correspondiente:
     * banca → banca_id, grupo → grupo_id, agencia → agencia_id, taquilla → taquilla_id.
     */
    private function validateRoleBindings(string $role, array $data, ?User $existing = null): void
    {
        $required = match ($role) {
            'banca' => 'banca_id',
            'grupo' => 'grupo_id',
            'agencia' => 'agencia_id',
            'taquilla' => 'taquilla_id',
            default => null,
        };

        if ($required === null) {
            return;
        }

        $hasBinding = $data[$required] ?? $existing?->{$required} ?? null;

        if (! $hasBinding) {
            throw ValidationException::withMessages([
                $required => ["Un usuario con rol '{$role}' debe estar vinculado a una entidad."],
            ]);
        }
    }

    /**
     * Resuelve la banca efectiva de un usuario recorriendo su cadena de vínculos.
     */
    private function resolveBancaId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }

        if ($user->banca_id) {
            return $user->banca_id;
        }

        if ($user->grupo_id) {
            return Grupo::find($user->grupo_id)?->banca_id;
        }

        if ($user->agencia_id) {
            return Agencia::find($user->agencia_id)?->grupo?->banca_id;
        }

        if ($user->taquilla_id) {
            return Taquilla::find($user->taquilla_id)?->grupo?->banca_id;
        }

        return null;
    }

    /**
     * Resuelve el grupo efectivo de un usuario recorriendo su cadena de vínculos.
     */
    private function resolveGrupoId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }

        if ($user->grupo_id) {
            return $user->grupo_id;
        }

        if ($user->agencia_id) {
            return Agencia::find($user->agencia_id)?->grupo_id;
        }

        if ($user->taquilla_id) {
            return Taquilla::find($user->taquilla_id)?->grupo_id;
        }

        return null;
    }

    /**
     * Resuelve el local (agencia) efectivo de un usuario recorriendo su cadena.
     */
    private function resolveAgenciaId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }

        if ($user->agencia_id) {
            return $user->agencia_id;
        }

        if ($user->taquilla_id) {
            return Taquilla::find($user->taquilla_id)?->agencia_id;
        }

        return null;
    }
}
