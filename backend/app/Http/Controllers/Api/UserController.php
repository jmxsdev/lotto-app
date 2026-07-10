<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
/*    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:manage_users')->only(['index', 'store', 'update', 'destroy']);
        $this->middleware('permission:view_users')->only(['show']);
    }
 */
    /**
     * Listar usuarios (filtrados según jerarquía)
     */
    public function index(Request $request)
    {
        $user = $request->user();
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
                // Si no tiene banca, solo se ve a sí mismo (o ninguno, según prefieras)
                $query->where('id', $user->id);
            }
        }
        // Otros roles no pueden listar usuarios
        else {
            return response()->json(['message' => 'No tienes permisos para ver usuarios.'], 403);
        }

        $users = $query->with('banca', 'grupo', 'taquilla', 'roles')->get();

        return response()->json($users);
    }

    /**
     * Crear un nuevo usuario
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(['super_master', 'master', 'banca', 'grupo', 'taquilla'])],
            'banca_id' => 'nullable|exists:bancas,id',
            'grupo_id' => 'nullable|exists:grupos,id',
            'taquilla_id' => 'nullable|exists:taquillas,id',
            'active' => 'boolean',
        ]);

        // Validar consistencia según rol
        $this->validateRoleConsistency($request);

        // Verificar que el usuario autenticado pueda asignar este rol y entidades
        $this->authorizeUserCreation($user, $request);

        $newUser = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'banca_id' => $request->banca_id,
            'grupo_id' => $request->grupo_id,
            'taquilla_id' => $request->taquilla_id,
            'active' => $request->active ?? true,
        ]);

        $newUser->assignRole($request->role, 'api');

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
        $this->authorizeUserAccess(auth()->user(), $user);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8',
            'role' => ['sometimes', Rule::in(['super_master', 'master', 'banca', 'grupo', 'taquilla'])],
            'banca_id' => 'nullable|exists:bancas,id',
            'grupo_id' => 'nullable|exists:grupos,id',
            'taquilla_id' => 'nullable|exists:taquillas,id',
            'active' => 'boolean',
        ]);

        $data = $request->only(['name', 'email', 'role', 'banca_id', 'grupo_id', 'taquilla_id', 'active']);
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        if ($request->has('role')) {
            $this->validateRoleConsistency($request);
            // Si cambia el rol, sincronizar
            $user->syncRoles([$request->role], 'api');
        }

        $user->update($data);

        return response()->json($user->load('roles'));
    }

    /**
     * Eliminar un usuario
     */
    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'No puedes eliminarte a ti mismo.'], 403);
        }

        $this->authorizeUserAccess(auth()->user(), $user);

        $user->delete();

        return response()->json(['message' => 'Usuario eliminado correctamente.']);
    }

    // --- Métodos de validación y autorización ---

    private function validateRoleConsistency(Request $request)
    {
        $role = $request->role;
        $banca_id = $request->banca_id;
        $grupo_id = $request->grupo_id;
        $taquilla_id = $request->taquilla_id;

        switch ($role) {
            case 'super_master':
            case 'master':
                // No requieren entidades
                break;
            case 'banca':
                if (!$banca_id) {
                    throw ValidationException::withMessages(['banca_id' => 'El rol "banca" requiere una banca asociada.']);
                }
                break;
            case 'grupo':
                if (!$grupo_id) {
                    throw ValidationException::withMessages(['grupo_id' => 'El rol "grupo" requiere un grupo asociado.']);
                }
                break;
            case 'taquilla':
                if (!$taquilla_id) {
                    throw ValidationException::withMessages(['taquilla_id' => 'El rol "taquilla" requiere una taquilla asociada.']);
                }
                break;
        }
    }

    private function authorizeUserCreation($currentUser, Request $request)
    {
        // Si es Super Master, puede crear cualquier usuario
        if ($currentUser->hasRole('super_master')) {
            return;
        }

        // Si es Master, solo puede crear usuarios de su banca
        if ($currentUser->hasRole('master')) {
            if (!$currentUser->banca_id) {
                abort(403, 'No tienes una banca asociada.');
            }
            if ($request->banca_id && $request->banca_id != $currentUser->banca_id) {
                abort(403, 'Solo puedes crear usuarios en tu banca.');
            }
            // No puede crear Super Master
            if ($request->role === 'super_master') {
                abort(403, 'No puedes crear un Super Master.');
            }
            return;
        }

        // Otros roles no pueden crear usuarios
        abort(403, 'No tienes permisos para crear usuarios.');
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

        abort(403, 'No tienes permiso para acceder a este usuario.');
    }
}
