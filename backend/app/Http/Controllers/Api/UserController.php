<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
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
     * Crear un nuevo usuario (solo Super Master y Master).
     * Para crear usuarios de banca/grupo/taquilla usar los endpoints
     * POST /api/bancas, POST /api/grupos, POST /api/taquillas respectivamente.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'user_name' => 'required|string|max:255',
            'user_email' => 'required|email|unique:users,email',
            'user_password' => 'required|string|min:8',
            'role' => ['required', Rule::in(['super_master', 'master'])],
            'active' => 'boolean',
        ]);

        // Verificar que el usuario autenticado pueda asignar este rol
        $this->authorizeUserCreation($user, $request);

        $newUser = User::create([
            'name' => $request->user_name,
            'email' => $request->user_email,
            'password' => Hash::make($request->user_password),
            'role' => $request->role,
            'active' => $request->active ?? true,
        ]);

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
        $this->authorizeUserAccess(auth()->user(), $user);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8',
            'role' => ['sometimes', Rule::in(['super_master', 'master', 'banca', 'grupo', 'taquilla'])],
            'active' => 'boolean',
        ]);

        $data = $request->only(['name', 'email', 'role', 'active']);

        if (isset($data['role']) && auth()->user()->hasRole('master') && $data['role'] === 'super_master') {
            return response()->json(['message' => 'No puedes asignar el rol super_master.'], 403);
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

    // --- Métodos de autorización ---

    private function authorizeUserCreation($currentUser, Request $request)
    {
        if ($currentUser->hasRole('super_master')) {
            return;
        }

        if ($currentUser->hasRole('master')) {
            if ($request->role === 'super_master') {
                abort(403, 'No puedes crear un Super Master.');
            }
            return;
        }

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
