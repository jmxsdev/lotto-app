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

        if ($user->hasRole('super_master')) {
            $bancas = Banca::withCount('grupos', 'users')->with('master')->get();
        } elseif ($user->hasRole('master')) {
            // master ve solo las bancas que administra; sin bancas ve NADA
            $query = Banca::query()->withCount('grupos', 'users')->with('master');
            $user->masterBancaScope('id')($query);
            $bancas = $query->get();
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
            'monedas_permitidas' => 'sometimes|array',
            'monedas_permitidas.bs' => 'boolean',
            'monedas_permitidas.usd' => 'boolean',
            'vigencia_premios' => 'nullable|integer|min:1',
            'tiempo_eliminacion' => 'nullable|integer|min:1|max:120',
            'master_id' => ['nullable', 'exists:users,id', function ($attribute, $value, $fail) {
                if ($value !== null && ! User::where('id', $value)->role('master')->exists()) {
                    $fail('El master seleccionado no tiene el rol master.');
                }
            }],
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

        $banca = Banca::create([
            'name' => $request->name,
            'code' => $request->code,
            'config' => $request->config,
            'monedas_permitidas' => $request->monedas_permitidas ?? null,
            'vigencia_premios' => $request->vigencia_premios ?? null,
            'tiempo_eliminacion' => $request->tiempo_eliminacion ?? null,
            'active' => $request->active ?? true,
            'created_by' => $authUser->id,
            // Si el creador es un master, la banca queda administrada por él
            // (coherente con el backfill F0: master_id = created_by).
            'master_id' => $authUser->hasRole('master') ? $authUser->id : ($request->master_id ?? null),
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

        if ($user->hasRole('super_master')) {
            // puede ver cualquier banca
        } elseif ($user->hasRole('master') && ! $user->masterCanAccessBanca($banca->id)) {
            return response()->json(['message' => 'No tienes permiso para ver esta banca.'], 403);
        } elseif (! $user->hasRole(['super_master', 'master'])) {
            return response()->json(['message' => 'No tienes permiso para ver esta banca.'], 403);
        }

        return response()->json($banca->load('grupos', 'users', 'master'));
    }

    public function update(Request $request, Banca $banca)
    {
        $user = auth()->user();

        if ($user->hasRole('super_master')) {
            // puede modificar cualquier banca
        } elseif ($user->hasRole('master') && ! $user->masterCanAccessBanca($banca->id)) {
            return response()->json(['message' => 'No tienes permiso para modificar esta banca.'], 403);
        } elseif (! $user->hasRole(['super_master', 'master'])) {
            return response()->json(['message' => 'No tienes permiso para modificar esta banca.'], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', Rule::unique('bancas')->ignore($banca->id)],
            'config' => 'nullable|array',
            'active' => 'boolean',
            'monedas_permitidas' => 'sometimes|array',
            'monedas_permitidas.bs' => 'boolean',
            'monedas_permitidas.usd' => 'boolean',
            'vigencia_premios' => 'nullable|integer|min:1',
            'tiempo_eliminacion' => 'nullable|integer|min:1|max:120',
            'master_id' => ['nullable', 'exists:users,id', function ($attribute, $value, $fail) {
                if ($value !== null && ! User::where('id', $value)->role('master')->exists()) {
                    $fail('El master seleccionado no tiene el rol master.');
                }
            }],
            'rif' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            'telefono' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
        ]);

        $banca->update($request->only(['name', 'code', 'config', 'active', 'monedas_permitidas', 'vigencia_premios', 'tiempo_eliminacion', 'master_id', 'rif', 'email', 'telefono', 'direccion', 'estado', 'municipio']));

        return response()->json($banca);
    }

    /**
     * Alternar el estado activo de una banca.
     * PATCH /api/bancas/{banca}/toggle
     * No propaga cambios a entidades hijas: el activo efectivo se resuelve en runtime.
     */
    public function toggle(Request $request, Banca $banca)
    {
        $user = auth()->user();

        if ($user->hasRole('super_master')) {
            // puede alternar cualquier banca
        } elseif ($user->hasRole('master') && ! $user->masterCanAccessBanca($banca->id)) {
            return response()->json(['message' => 'No tienes permiso para modificar esta banca.'], 403);
        } elseif (! $user->hasRole(['super_master', 'master'])) {
            return response()->json(['message' => 'No tienes permiso para modificar esta banca.'], 403);
        }

        $banca->update(['active' => ! $banca->active]);

        return response()->json($banca);
    }

    public function destroy(Banca $banca)
    {
        $user = auth()->user();

        if ($user->hasRole('super_master')) {
            // puede eliminar cualquier banca
        } elseif ($user->hasRole('master') && ! $user->masterCanAccessBanca($banca->id)) {
            return response()->json(['message' => 'No tienes permiso para eliminar esta banca.'], 403);
        } elseif (! $user->hasRole(['super_master', 'master'])) {
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
