<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banca;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class BancaController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->hasRole(['super_master', 'master'])) {
            $bancas = Banca::withCount('grupos', 'users')->get();
        } else {
            return response()->json(['message' => 'No tienes permiso para ver bancas.'], 403);
        }

        return response()->json($bancas);
    }

    public function store(Request $request)
    {
        $authUser = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:bancas,code',
            'config' => 'nullable|array',
            'active' => 'boolean',
            'user_name' => 'required|string|max:255',
            'user_email' => 'required|email|unique:users,email',
            'user_password' => 'required|string|min:8',
        ]);

        $banca = Banca::create([
            'name' => $request->name,
            'code' => $request->code,
            'config' => $request->config,
            'active' => $request->active ?? true,
            'created_by' => $authUser->id,
        ]);

        $user = User::create([
            'name' => $request->user_name,
            'email' => $request->user_email,
            'password' => Hash::make($request->user_password),
            'role' => 'banca',
            'banca_id' => $banca->id,
            'active' => $request->active ?? true,
        ]);

        $user->assignRole('banca');

        return response()->json([
            'banca' => $banca,
            'user' => $user->load('roles'),
        ], 201);
    }

    public function show(Banca $banca)
    {
        $user = auth()->user();

        if (!$user->hasRole(['super_master', 'master'])) {
            return response()->json(['message' => 'No tienes permiso para ver esta banca.'], 403);
        }

        return response()->json($banca->load('grupos', 'users'));
    }

    public function update(Request $request, Banca $banca)
    {
        $user = auth()->user();

        if (!$user->hasRole(['super_master', 'master'])) {
            return response()->json(['message' => 'No tienes permiso para modificar esta banca.'], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', Rule::unique('bancas')->ignore($banca->id)],
            'config' => 'nullable|array',
            'active' => 'boolean',
        ]);

        $banca->update($request->only(['name', 'code', 'config', 'active']));

        return response()->json($banca);
    }

    public function destroy(Banca $banca)
    {
        $user = auth()->user();

        if (!$user->hasRole(['super_master', 'master'])) {
            return response()->json(['message' => 'No tienes permiso para eliminar esta banca.'], 403);
        }

        if ($banca->grupos()->count() > 0) {
            return response()->json(['message' => 'No se puede eliminar la banca porque tiene grupos asociados.'], 422);
        }

        if ($banca->users()->count() > 0) {
            return response()->json(['message' => 'No se puede eliminar la banca porque tiene usuarios asociados.'], 422);
        }

        $banca->delete();

        return response()->json(['message' => 'Banca eliminada correctamente.']);
    }
}
