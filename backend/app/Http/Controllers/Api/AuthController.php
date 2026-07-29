<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales incorrectas'], 401);
        }

        if (!$user->active) {
            return response()->json(['message' => 'Usuario inactivo'], 403);
        }

        if ($user->role === 'taquilla' && $user->taquilla_id) {
            $taquilla = $user->taquilla;
            if (!$taquilla || !$taquilla->active) {
                return response()->json(['message' => 'Este dispositivo no ha sido activado. Use el código de activación.'], 403);
            }
            if (!$taquilla->mac_address) {
                return response()->json(['message' => 'Dispositivo no registrado. Complete la activación.'], 403);
            }
        }

        $token = $user->createToken('taquilla-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
            'role' => $user->role,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada']);
    }

    public function user(Request $request)
    {
        return $request->user();
    }
}
