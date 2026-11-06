<?php

namespace App\Http\Controllers\Api;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use App\Audit\AuditRedactor;
use App\Audit\AuditScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivacionEfectivaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(
        Request $request,
        AuditRecorder $recorder,
        AuditContext $context,
        AuditRedactor $redactor,
        AuditScopeResolver $scopeResolver,
    ) {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // P0 fail-closed: cada rama de fallo y el token comparten transacción
        // con su evento de auditoría. Si la escritura falla, el token no
        // persiste y la respuesta es 500 (nunca un 2xx/4xx silencioso).
        return DB::transaction(function () use ($credentials, $request, $recorder, $context, $redactor, $scopeResolver) {
            $user = User::where('email', $credentials['email'])->first();

            if (! $user || ! Hash::check($credentials['password'], $user->password)) {
                $this->recordLoginFailed($recorder, $context, $redactor, $credentials['email'], 'bad_credentials');

                return response()->json(['message' => 'Credenciales incorrectas'], 401);
            }

            if (! $user->active) {
                $this->recordLoginFailed($recorder, $context, $redactor, $user->email, 'inactive_user');

                return response()->json(['message' => 'Usuario inactivo'], 403);
            }

            // Fail-closed: sin rol Spatie no hay flujo de login válido; nunca se
            // infiere un rol desde la columna legacy (fuente única: Spatie).
            if ($user->primaryRole() === null) {
                $this->recordLoginFailed($recorder, $context, $redactor, $user->email, 'no_role');

                return response()->json(['message' => 'Usuario sin rol asignado.'], 403);
            }

            if ($user->hasRole('taquilla') && $user->taquilla_id) {
                $taquilla = $user->taquilla;

                if (! $taquilla) {
                    $this->recordLoginFailed($recorder, $context, $redactor, $user->email, 'taquilla_missing');

                    return response()->json(['message' => 'Este dispositivo no ha sido activado. Use el código de activación.'], 403);
                }

                if (! $taquilla->active) {
                    $this->recordLoginFailed($recorder, $context, $redactor, $user->email, 'taquilla_inactive');

                    return response()->json(['message' => 'Este dispositivo no ha sido activado. Use el código de activación.'], 403);
                }

                if (! $taquilla->mac_address || ! $taquilla->device_fingerprint) {
                    $this->recordLoginFailed($recorder, $context, $redactor, $user->email, 'taquilla_not_registered');

                    return response()->json(['message' => 'Dispositivo no registrado. Complete la activación.'], 403);
                }

                $reqFingerprint = $request->header('X-Device-Fingerprint');

                if (! $reqFingerprint || $reqFingerprint !== $taquilla->device_fingerprint) {
                    $this->recordLoginFailed($recorder, $context, $redactor, $user->email, 'device_fingerprint_mismatch');

                    return response()->json(['message' => 'Huella digital del dispositivo no coincide.'], 403);
                }
            }

            // Verificar activación efectiva de la cadena (banca → grupo → agencia).
            // El flag propio de la agencia ya está cubierto por el flujo de activación;
            // aquí se bloquea cuando un ancestro está inactivo. super_master/master no aplican.
            $bloqueoCadena = $this->bloqueoCadenaInactiva($user);

            if ($bloqueoCadena !== null) {
                $this->recordLoginFailed($recorder, $context, $redactor, $user->email, 'chain_inactive', [
                    'chain_cause' => $bloqueoCadena['cause'],
                    'chain_message' => $bloqueoCadena['message'],
                ]);

                return response()->json(['message' => $bloqueoCadena['message']], 403);
            }

            // Validar rol según tipo de cliente (fuente única: Spatie)
            if ($request->header('X-Panel') === 'true') {
                if (! $user->hasAnyRole(['super_master', 'master', 'banca', 'grupo', 'agencia'])) {
                    $this->recordLoginFailed($recorder, $context, $redactor, $user->email, 'panel_wrong_role');

                    return response()->json(['message' => 'Las taquillas deben usar la app de escritorio.'], 403);
                }
            } else {
                if (! $user->hasRole('taquilla')) {
                    $this->recordLoginFailed($recorder, $context, $redactor, $user->email, 'taquilla_required');

                    return response()->json(['message' => 'Use el panel de administración para acceder.'], 403);
                }
            }

            $newToken = $user->createToken('taquilla-token');

            $loginEvent = $this->authEventData(
                action: AuditAction::AuthLoginSucceeded,
                context: $context,
                scopeResolver: $scopeResolver,
                user: $user,
                extraContext: ['token_id' => $newToken->accessToken->id],
            );

            $recorder->record(AuditAction::AuthLoginSucceeded, $loginEvent);

            return response()->json([
                'token' => $newToken->plainTextToken,
                'user' => $user,
                'role' => $user->primaryRole(),
            ]);
        });
    }

    public function logout(
        Request $request,
        AuditRecorder $recorder,
        AuditContext $context,
        AuditScopeResolver $scopeResolver,
    ) {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $tokenId = $token?->id;

        DB::transaction(function () use ($recorder, $context, $scopeResolver, $user, $token, $tokenId): void {
            $token?->delete();

            $logoutEvent = $this->authEventData(
                action: AuditAction::AuthLogout,
                context: $context,
                scopeResolver: $scopeResolver,
                user: $user,
                extraContext: ['token_id' => $tokenId],
            );

            $recorder->record(AuditAction::AuthLogout, $logoutEvent);

            // P1 best-effort: la revocación ya ocurrió; su evidencia no
            // bloquea el cierre de sesión si la escritura falla.
            $revokedEvent = $this->authEventData(
                action: AuditAction::AuthTokenRevoked,
                context: $context,
                scopeResolver: $scopeResolver,
                user: $user,
                extraContext: ['token_id' => $tokenId],
            );

            $recorder->bestEffort(AuditAction::AuthTokenRevoked, $revokedEvent);
        });

        return response()->json(['message' => 'Sesión cerrada']);
    }

    public function user(Request $request)
    {
        return $request->user();
    }

    /**
     * Build the envelope for authenticated auth events (login success, logout).
     * The plaintext token is never passed here; only its database id.
     */
    private function authEventData(
        AuditAction $action,
        AuditContext $context,
        AuditScopeResolver $scopeResolver,
        User $user,
        array $extraContext = [],
    ): AuditEventData {
        return new AuditEventData(
            action: $action,
            actorId: (int) $user->id,
            actorLabel: $user->email,
            actorRoles: $user->getRoleNames()->all(),
            scope: $scopeResolver->forModel($user),
            context: array_merge([
                'client' => $context->client() ?? 'api',
                'source' => 'api',
            ], $extraContext),
        );
    }

    /**
     * Record `auth.login_failed` with a masked attempted email and a stable
     * failure reason. The actor stays anonymous: the request never
     * authenticated, and the identity is not confirmed.
     */
    private function recordLoginFailed(
        AuditRecorder $recorder,
        AuditContext $context,
        AuditRedactor $redactor,
        string $attemptedEmail,
        string $failureReason,
        array $extraContext = [],
    ): void {
        $context->setActor(null);

        $recorder->record(AuditAction::AuthLoginFailed, new AuditEventData(
            action: AuditAction::AuthLoginFailed,
            context: array_merge([
                'attempted_email' => $redactor->maskEmail($attemptedEmail),
                'failure_reason' => $failureReason,
                'client' => $context->client() ?? 'api',
                'source' => 'api',
            ], $extraContext),
        ));
    }

    /**
     * Devuelve causa y mensaje de bloqueo si la cadena de la entidad del
     * usuario (banca → grupo → agencia) contiene una entidad inactiva.
     * Bindings faltantes se consideran activos; super_master/master no aplican.
     * El rol se resuelve siempre por Spatie (REQ-1).
     *
     * @return array{cause: 'agencia'|'grupo'|'banca', message: string}|null
     */
    private function bloqueoCadenaInactiva(User $user): ?array
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
                'agencia' => ['cause' => 'agencia', 'message' => 'Tu cuenta está pausada porque su local está desactivado.'],
                'grupo' => ['cause' => 'grupo', 'message' => 'Tu cuenta está pausada porque su grupo está desactivado.'],
                default => ['cause' => 'banca', 'message' => 'Tu cuenta está pausada porque su banca está desactivada.'],
            };
        }

        if ($user->hasRole('agencia') && $user->agencia_id) {
            $agencia = $user->agencia;
            if (! $agencia) {
                return null;
            }
            if (! $agencia->active) {
                return ['cause' => 'agencia', 'message' => 'Tu cuenta está pausada porque su local está desactivado.'];
            }
            if ($agencia->grupo && ! $agencia->grupo->active) {
                return ['cause' => 'grupo', 'message' => 'Tu cuenta está pausada porque su grupo está desactivado.'];
            }
            if ($agencia->grupo?->banca && ! $agencia->grupo->banca->active) {
                return ['cause' => 'banca', 'message' => 'Tu cuenta está pausada porque su banca está desactivada.'];
            }

            return null;
        }

        if ($user->hasRole('grupo') && $user->grupo_id) {
            $grupo = $user->grupo;
            if (! $grupo) {
                return null;
            }
            if (! $grupo->active) {
                return ['cause' => 'grupo', 'message' => 'Tu cuenta está pausada porque su grupo está desactivado.'];
            }
            if ($grupo->banca && ! $grupo->banca->active) {
                return ['cause' => 'banca', 'message' => 'Tu cuenta está pausada porque su banca está desactivada.'];
            }

            return null;
        }

        if ($user->hasRole('banca') && $user->banca_id) {
            $banca = $user->banca;
            if (! $banca) {
                return null;
            }
            if (! $banca->active) {
                return ['cause' => 'banca', 'message' => 'Tu cuenta está pausada porque su banca está desactivada.'];
            }

            return null;
        }

        return null;
    }
}
