<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grupo;
use App\Models\Taquilla;
use App\Models\User;
use Illuminate\Http\Request;
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
        ]);

        $query = User::query();

        // Super Master ve todos
        if ($user->hasRole('super_master')) {
            // Sin filtro
        }
        // Master ve solo los de su banca (si tiene)
        elseif ($user->hasRole('master')) {
            if ($user->banca_id) {
                $query->where('banca_id', $user->banca_id);
            } else {
                $query->where('id', $user->id);
            }
        }
        // Banca ve los usuarios de su banca (por vínculo directo o por cadena)
        elseif ($user->hasRole('banca')) {
            if (!$user->banca_id) {
                return response()->json(['message' => 'No tienes una banca asociada.'], 403);
            }
            $query->where(function ($q) use ($user) {
                $q->where('banca_id', $user->banca_id)
                    ->orWhereHas('taquilla', fn ($t) => $t->whereHas('grupo', fn ($g) => $g->where('banca_id', $user->banca_id)));
            });
        }
        // Grupo ve los usuarios de su grupo y de sus agencias
        elseif ($user->hasRole('grupo')) {
            if (!$user->grupo_id) {
                return response()->json(['message' => 'No tienes un grupo asociado.'], 403);
            }
            $query->where(function ($q) use ($user) {
                $q->where('grupo_id', $user->grupo_id)
                    ->orWhereHas('taquilla', fn ($t) => $t->where('grupo_id', $user->grupo_id));
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

        if (isset($filtros['taquilla_id'])) {
            $query->where('taquilla_id', $filtros['taquilla_id']);
        }

        $users = $query->with('banca', 'grupo', 'taquilla', 'roles')->get();

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
            'role' => ['required', Rule::in(['super_master', 'master', 'banca', 'grupo', 'taquilla'])],
            'active' => 'boolean',
            'banca_id' => 'nullable|exists:bancas,id',
            'grupo_id' => 'nullable|exists:grupos,id',
            'taquilla_id' => 'nullable|exists:taquillas,id',
        ]);

        // Derivar la cadena de vínculos (taquilla → grupo → banca)
        $bindings = $this->deriveEntityBindings($request);

        $data = [
            'name' => $request->user_name,
            'email' => $request->user_email,
            'password' => Hash::make($request->user_password),
            'role' => $request->role,
            'active' => $request->active ?? true,
        ] + $bindings;

        // Consistencia rol ↔ vínculo de entidad
        $this->validateRoleBindings($request->role, $data);

        // Verificar que el usuario autenticado pueda asignar este rol y vínculos
        $this->authorizeEntityBinding($user, $data, $request->role);

        $newUser = User::create($data);

        $newUser->assignRole($request->role);

        return response()->json($newUser->load('roles'), 201);
    }

    /**
     * Mostrar un usuario específico
     */
    public function show(User $user)
    {
        $this->authorizeUserAccess(auth()->user(), $user);

        return response()->json($user->load('banca', 'grupo', 'taquilla', 'roles'));
    }

    /**
     * Actualizar un usuario
     */
    public function update(Request $request, User $user)
    {
        $currentUser = auth()->user();

        $this->authorizeUserAccess($currentUser, $user);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8',
            'role' => ['sometimes', Rule::in(['super_master', 'master', 'banca', 'grupo', 'taquilla'])],
            'active' => 'boolean',
            'banca_id' => 'nullable|exists:bancas,id',
            'grupo_id' => 'nullable|exists:grupos,id',
            'taquilla_id' => 'nullable|exists:taquillas,id',
        ]);

        $data = $request->only(['name', 'email', 'role', 'active']);

        // Derivar los nuevos vínculos de entidad si se enviaron
        $bindings = $this->deriveEntityBindings($request);
        if ($bindings !== []) {
            $data = array_merge($data, $bindings);
        }

        $finalRole = $data['role'] ?? $user->role;

        // Al cambiar rol o vínculos: validar consistencia y autorización jerárquica
        if (isset($data['role']) || $bindings !== []) {
            $this->validateRoleBindings($finalRole, $data, $user);
            $this->authorizeEntityBinding($currentUser, $data, $finalRole, $user);
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        if ($request->has('role')) {
            $user->syncRoles([$request->role]);
        }

        $user->update($data);

        return response()->json($user->load('roles'));
    }

    /**
     * Eliminar un usuario (solo Super Master y Master)
     */
    public function destroy(User $user)
    {
        if (!auth()->user()->hasRole(['super_master', 'master'])) {
            abort(403, 'No tienes permisos para eliminar usuarios.');
        }

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'No puedes eliminarte a ti mismo.'], 403);
        }

        $this->authorizeUserAccess(auth()->user(), $user);

        $user->delete();

        return response()->json(['message' => 'Usuario eliminado correctamente.']);
    }

    // --- Métodos de autorización ---

    /**
     * Autorizar la creación/asignación de un usuario con un rol y unos
     * vínculos de entidad, según la jerarquía del usuario autenticado.
     *
     * super_master: todo; master: cualquier banca (no super_master);
     * banca: solo entidades de su banca; grupo: solo entidades de su grupo.
     */
    private function authorizeEntityBinding($currentUser, array $data, string $role, ?User $existing = null)
    {
        if ($currentUser->hasRole('super_master')) {
            return;
        }

        if ($currentUser->hasRole('master')) {
            if ($role === 'super_master') {
                abort(403, 'No puedes asignar el rol super_master.');
            }
            return;
        }

        if ($currentUser->hasRole('banca')) {
            if (!$currentUser->banca_id) {
                abort(403, 'No tienes una banca asociada.');
            }
            if (in_array($role, ['super_master', 'master'], true)) {
                abort(403, 'No tienes permisos para asignar este rol.');
            }
            $effectiveBanca = $data['banca_id'] ?? $this->resolveBancaId($existing);
            if ($effectiveBanca != $currentUser->banca_id) {
                abort(403, 'No puedes vincular usuarios a entidades de otra banca.');
            }
            return;
        }

        if ($currentUser->hasRole('grupo')) {
            if (!$currentUser->grupo_id) {
                abort(403, 'No tienes un grupo asociado.');
            }
            if (in_array($role, ['super_master', 'master', 'banca'], true)) {
                abort(403, 'No tienes permisos para asignar este rol.');
            }
            $effectiveGrupo = $data['grupo_id'] ?? $this->resolveGrupoId($existing);
            if ($effectiveGrupo != $currentUser->grupo_id) {
                abort(403, 'No puedes vincular usuarios a entidades de otro grupo.');
            }
            return;
        }

        abort(403, 'No tienes permisos para gestionar usuarios.');
    }

    private function authorizeUserAccess($currentUser, User $targetUser)
    {
        if ($currentUser->hasRole('super_master')) {
            return;
        }

        if ($currentUser->hasRole('master')) {
            if (!$currentUser->banca_id) {
                abort(403, 'No tienes una banca asociada.');
            }
            if ($targetUser->banca_id && $targetUser->banca_id != $currentUser->banca_id) {
                abort(403, 'No tienes acceso a este usuario.');
            }
            return;
        }

        // Banca solo puede gestionar usuarios dentro de su banca (vía cadena)
        if ($currentUser->hasRole('banca')) {
            if (!$currentUser->banca_id) {
                abort(403, 'No tienes una banca asociada.');
            }
            if ($this->resolveBancaId($targetUser) != $currentUser->banca_id) {
                abort(403, 'No tienes acceso a este usuario.');
            }
            return;
        }

        // Grupo solo puede gestionar usuarios dentro de su grupo (vía cadena)
        if ($currentUser->hasRole('grupo')) {
            if (!$currentUser->grupo_id) {
                abort(403, 'No tienes un grupo asociado.');
            }
            if ($this->resolveGrupoId($targetUser) != $currentUser->grupo_id) {
                abort(403, 'No tienes acceso a este usuario.');
            }
            return;
        }

        abort(403, 'No tienes permiso para acceder a este usuario.');
    }

    // --- Helpers de vínculos ---

    /**
     * Deriva la cadena de vínculos a partir de lo enviado:
     * taquilla_id → banca_id + grupo_id; grupo_id → banca_id.
     * Limpia los vínculos inferiores cuando se vincula a un nivel superior.
     */
    private function deriveEntityBindings(Request $request): array
    {
        if ($request->filled('taquilla_id')) {
            $taquilla = Taquilla::find($request->taquilla_id);

            return [
                'taquilla_id' => $taquilla->id,
                'grupo_id' => $taquilla->grupo_id,
                'banca_id' => $taquilla->grupo->banca_id,
            ];
        }

        if ($request->filled('grupo_id')) {
            $grupo = Grupo::find($request->grupo_id);

            return [
                'taquilla_id' => null,
                'grupo_id' => $grupo->id,
                'banca_id' => $grupo->banca_id,
            ];
        }

        if ($request->filled('banca_id')) {
            return [
                'taquilla_id' => null,
                'grupo_id' => null,
                'banca_id' => $request->banca_id,
            ];
        }

        return [];
    }

    /**
     * Valida que el rol requiera (y tenga) el vínculo de entidad correspondiente:
     * banca → banca_id, grupo → grupo_id, taquilla → taquilla_id.
     */
    private function validateRoleBindings(string $role, array $data, ?User $existing = null): void
    {
        $required = match ($role) {
            'banca' => 'banca_id',
            'grupo' => 'grupo_id',
            'taquilla' => 'taquilla_id',
            default => null,
        };

        if ($required === null) {
            return;
        }

        $hasBinding = $data[$required] ?? $existing?->{$required} ?? null;

        if (!$hasBinding) {
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
        if (!$user) {
            return null;
        }

        if ($user->banca_id) {
            return $user->banca_id;
        }

        if ($user->grupo_id) {
            return Grupo::find($user->grupo_id)?->banca_id;
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
        if (!$user) {
            return null;
        }

        if ($user->grupo_id) {
            return $user->grupo_id;
        }

        if ($user->taquilla_id) {
            return Taquilla::find($user->taquilla_id)?->grupo_id;
        }

        return null;
    }
}
