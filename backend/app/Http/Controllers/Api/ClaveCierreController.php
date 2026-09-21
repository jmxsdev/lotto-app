<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ClaveCierreController extends Controller
{
    /**
     * Estado self-service de la clave de cierre del usuario autenticado
     * (AD-9): solo el booleano, nunca el hash.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'clave_configurada' => (bool) $request->user()->clave_cierre,
        ]);
    }

    /**
     * Configurar o cambiar la clave de cierre del usuario autenticado
     * (AD-10, Q1/Q5).
     *
     * Primera configuración: directa con `clave_nueva`. Cambio: exige
     * `clave_actual` verificada con Hash::check antes de persistir; si no
     * coincide responde 422 y la clave queda intacta. El formato es un PIN
     * numérico de 4 a 8 dígitos. `clave_nueva_confirma` es UI-only
     * (decisión 2.1): el servidor no la valida con `same:`.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $rules = [
            'clave_nueva' => 'required|digits_between:4,8',
            'clave_actual' => 'nullable|digits_between:4,8',
        ];

        if ($user->clave_cierre) {
            $rules['clave_actual'] = 'required|digits_between:4,8';
        }

        $validated = $request->validate($rules);

        if ($user->clave_cierre && ! Hash::check($validated['clave_actual'], $user->clave_cierre)) {
            return response()->json(['message' => 'La clave actual no coincide.'], 422);
        }

        $user->update(['clave_cierre' => Hash::make($validated['clave_nueva'])]);

        return response()->json(['clave_configurada' => true]);
    }
}
