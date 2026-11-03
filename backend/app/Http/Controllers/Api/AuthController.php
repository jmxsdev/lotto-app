<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivacionEfectivaService;
use Illuminate\Http\Request;
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

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales incorrectas'], 401);
        }

        if (! $user->active) {
            return response()->json(['message' => 'Usuario inactivo'], 403);
        }

        // Fail-closed: sin rol Spatie no hay flujo de login válido; nunca se
        // infiere un rol desde la columna legacy (fuente única: Spatie).
        if ($user->primaryRole() === null) {
            return response()->json(['message' => 'Usuario sin rol asignado.'], 403);
        }

        if ($user->hasRole('taquilla') && $user->taquilla_id) {
            $taquilla = $user->taquilla;
            if (! $taquilla || ! $taquilla->active) {
                return response()->json(['message' => 'Este dispositivo no ha sido activado. Use el código de activación.'], 403);
            }
            if (! $taquilla->mac_address || ! $taquilla->device_fingerprint) {
                return response()->json(['message' => 'Dispositivo no registrado. Complete la activación.'], 403);
            }
            $reqFingerprint = $request->header('X-Device-Fingerprint');
            if (! $reqFingerprint || $reqFingerprint !== $taquilla->device_fingerprint) {
                return response()->json(['message' => 'Huella digital del dispositivo no coincide.'], 403);
            }
        }

        // Verificar activación efectiva de la cadena (banca → grupo → agencia).
        // El flag propio de la agencia ya está cubierto por el flujo de activación;
        // aquí se bloquea cuando un ancestro está inactivo. super_master/master no aplican.
        $mensajeCadena = $this->mensajeCadenaInactiva($user);
        if ($mensajeCadena) {
            return response()->json(['message' => $mensajeCadena], 403);
        }

        // Validar rol según tipo de cliente (fuente única: Spatie)
        if ($request->header('X-Panel') === 'true') {
            if (! $user->hasAnyRole(['super_master', 'master', 'banca', 'grupo', 'agencia'])) {
                return response()->json(['message' => 'Las taquillas deben usar la app de escritorio.'], 403);
            }
        } else {
            if (! $user->hasRole('taquilla')) {
                return response()->json(['message' => 'Use el panel de administración para acceder.'], 403);
            }
        }

        $token = $user->createToken('taquilla-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
            'role' => $user->primaryRole(),
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

    /**
     * Devuelve el mensaje de bloqueo si la cadena de la entidad del usuario
     * (banca → grupo → agencia) contiene una entidad inactiva, o null si puede entrar.
     * Bindings faltantes se consideran activos; super_master/master no aplican.
     * El rol se resuelve siempre por Spatie (REQ-1).
     */
    private function mensajeCadenaInactiva(User $user): ?string
    {
        if ($user->hasRole('taquilla') && $user->taquilla_id) {
            $taquilla = $user->taquilla;
            if (! $taquilla) {
                return null;
            }

            $estado = app(ActivacionEfectivaService::class)->estadoTaquilla($taquilla);
            if ($estado['active'] || $estado['causa'] === 'taquilla') {
                // Flag propio: cubierto por el flujo de activación previo
                return null;
            }

            return match ($estado['causa']) {
                'agencia' => 'Tu cuenta está pausada porque su local está desactivado.',
                'grupo' => 'Tu cuenta está pausada porque su grupo está desactivado.',
                default => 'Tu cuenta está pausada porque su banca está desactivada.',
            };
        }

        if ($user->hasRole('agencia') && $user->agencia_id) {
            $agencia = $user->agencia;
            if (! $agencia) {
                return null;
            }
            if (! $agencia->active) {
                return 'Tu cuenta está pausada porque su local está desactivado.';
            }
            if ($agencia->grupo && ! $agencia->grupo->active) {
                return 'Tu cuenta está pausada porque su grupo está desactivado.';
            }
            if ($agencia->grupo?->banca && ! $agencia->grupo->banca->active) {
                return 'Tu cuenta está pausada porque su banca está desactivada.';
            }

            return null;
        }

        if ($user->hasRole('grupo') && $user->grupo_id) {
            $grupo = $user->grupo;
            if (! $grupo) {
                return null;
            }
            if (! $grupo->active) {
                return 'Tu cuenta está pausada porque su grupo está desactivado.';
            }
            if ($grupo->banca && ! $grupo->banca->active) {
                return 'Tu cuenta está pausada porque su banca está desactivada.';
            }

            return null;
        }

        if ($user->hasRole('banca') && $user->banca_id) {
            $banca = $user->banca;
            if (! $banca) {
                return null;
            }
            if (! $banca->active) {
                return 'Tu cuenta está pausada porque su banca está desactivada.';
            }

            return null;
        }

        return null;
    }
}
