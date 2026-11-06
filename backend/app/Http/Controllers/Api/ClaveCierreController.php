<?php

namespace App\Http\Controllers\Api;

use App\Audit\AuditAction;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditRecorder;
use App\Audit\AuditScopeResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     *
     * La mutación y el evento P0 `clave_cierre.updated` comparten transacción:
     * si la evidencia no se persiste, la clave tampoco cambia. El evento solo
     * registra que hubo cambio (`context.changed`); nunca la clave, su hash ni
     * los campos `clave_actual`/`clave_nueva`.
     */
    public function update(Request $request, AuditRecorder $recorder): JsonResponse
    {
        $user = $request->user();

        $rules = [
            'clave_nueva' => 'required|digits_between:4,8',
            'clave_actual' => 'nullable|digits_between:4,8',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ];

        if ($user->clave_cierre) {
            $rules['clave_actual'] = 'required|digits_between:4,8';
        }

        $validated = $request->validate($rules);

        if ($user->clave_cierre && ! Hash::check($validated['clave_actual'], $user->clave_cierre)) {
            return response()->json(['message' => 'La clave actual no coincide.'], 422);
        }

        $reasonRaw = $validated['reason_code'] ?? null;
        $reason = is_string($reasonRaw) ? AuditReason::tryFrom($reasonRaw) : null;
        $reasonTextRaw = $validated['reason_text'] ?? null;
        $reasonText = is_string($reasonTextRaw) ? $reasonTextRaw : null;

        DB::transaction(function () use ($user, $recorder, $validated, $reason, $reasonText): void {
            $user->update(['clave_cierre' => Hash::make($validated['clave_nueva'])]);

            $event = (new AuditEventData(
                action: AuditAction::ClaveCierreUpdated,
                entityType: 'configuracion',
                entityId: (int) $user->id,
                scope: app(AuditScopeResolver::class)->forModel($user),
                context: ['changed' => true],
            ))->withReason($reason, $reasonText);

            $recorder->record(AuditAction::ClaveCierreUpdated, $event);
        });

        return response()->json(['clave_configurada' => true]);
    }
}
