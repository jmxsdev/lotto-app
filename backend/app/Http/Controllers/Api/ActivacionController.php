<?php

namespace App\Http\Controllers\Api;

use App\Audit\AuditAction;
use App\Audit\AuditActorType;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use App\Audit\AuditRedactor;
use App\Audit\AuditScope;
use App\Audit\AuditScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\Log;
use App\Models\Taquilla;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ActivacionController extends Controller
{
    /**
     * Activar una taquilla con código y MAC address
     * Endpoint público (sin autenticación)
     */
    public function activar(
        Request $request,
        AuditRecorder $recorder,
        AuditRedactor $redactor,
        AuditScopeResolver $scopeResolver,
    ) {
        // Validar input
        $validator = Validator::make($request->all(), [
            'activation_code' => 'required|string|max:32',
            'mac_address' => 'required|string|regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
            'device_fingerprint' => 'required|string|max:255',
        ], [
            'activation_code.required' => 'El código de activación es obligatorio.',
            'mac_address.required' => 'La dirección MAC es obligatoria.',
            'mac_address.regex' => 'El formato de la dirección MAC no es válido (ej: AA:BB:CC:DD:EE:FF).',
            'device_fingerprint.required' => 'La huella digital del dispositivo es obligatoria.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $codigo = $request->input('activation_code');
        $mac = strtoupper($request->input('mac_address'));
        $fingerprint = $request->input('device_fingerprint');

        // P0 fail-closed: la mutación de taquilla y su evidencia comparten
        // transacción. Si la escritura de auditoría falla, no queda activación.
        return DB::transaction(function () use ($codigo, $mac, $fingerprint, $recorder, $redactor, $scopeResolver) {
            // Buscar taquilla por activation_code
            $taquilla = Taquilla::where('activation_code', $codigo)->first();

            if (! $taquilla) {
                $recorder->record(AuditAction::TaquillaActivationFailed, $this->activationEvent(
                    action: AuditAction::TaquillaActivationFailed,
                    actorId: null,
                    actorLabel: 'device:'.$redactor->maskMac($mac),
                    entityId: null,
                    context: [
                        'failure_reason' => 'code_not_found',
                        'activation_code' => $codigo,
                        'mac_address' => $mac,
                    ],
                ));

                $this->logActivacion(null, $codigo, $mac, false, 'Código de activación no encontrado', $redactor);

                return response()->json([
                    'success' => false,
                    'message' => 'Código de activación inválido.',
                ], 404);
            }

            // Verificar si ya está activa con esta misma MAC
            if ($taquilla->active && $taquilla->mac_address === $mac) {
                $before = [
                    'active' => $taquilla->active,
                    'mac_address' => $taquilla->mac_address,
                    'device_fingerprint' => $taquilla->device_fingerprint,
                    'last_connection_at' => $taquilla->last_connection_at?->toIso8601String(),
                ];

                $taquilla->update(['last_connection_at' => now()]);

                $recorder->record(AuditAction::TaquillaActivationSucceeded, $this->activationEvent(
                    action: AuditAction::TaquillaActivationSucceeded,
                    actorId: $taquilla->id,
                    actorLabel: 'taquilla:'.$taquilla->id,
                    scope: $scopeResolver->forModel($taquilla),
                    entityId: $taquilla->id,
                    before: $before,
                    after: [
                        'active' => $taquilla->active,
                        'mac_address' => $taquilla->mac_address,
                        'device_fingerprint' => $taquilla->device_fingerprint,
                        'last_connection_at' => $taquilla->last_connection_at?->toIso8601String(),
                    ],
                    context: [
                        'mode' => 'reactivation_same_mac',
                        'activation_code' => $codigo,
                    ],
                ));

                $this->logActivacion($taquilla->id, $codigo, $mac, true, 'Reactivación - misma MAC', $redactor);

                return response()->json([
                    'success' => true,
                    'message' => 'Taquilla reactivada exitosamente.',
                    'data' => [
                        'taquilla_id' => $taquilla->id,
                        'name' => $taquilla->name,
                        'code' => $taquilla->code,
                        'mac_address' => $taquilla->mac_address,
                        'active' => true,
                    ],
                ]);
            }

            // Si ya está activa con otra MAC, rechazar (evitar hijacking)
            if ($taquilla->active && $taquilla->mac_address !== $mac) {
                $recorder->record(AuditAction::TaquillaActivationFailed, $this->activationEvent(
                    action: AuditAction::TaquillaActivationFailed,
                    actorId: $taquilla->id,
                    actorLabel: 'taquilla:'.$taquilla->id,
                    scope: $scopeResolver->forModel($taquilla),
                    entityId: $taquilla->id,
                    context: [
                        'failure_reason' => 'mac_mismatch',
                        'activation_code' => $codigo,
                        'mac_address' => $mac,
                    ],
                ));

                $this->logActivacion($taquilla->id, $codigo, $mac, false, 'Intento de activación con MAC diferente', $redactor);

                return response()->json([
                    'success' => false,
                    'message' => 'Esta taquilla ya está activada con otra dirección MAC.',
                ], 403);
            }

            // Si hay otra taquilla activa con esta MAC, desactivarla (reasignación automática)
            $otraTaquilla = Taquilla::where('mac_address', $mac)
                ->where('active', true)
                ->where('id', '!=', $taquilla->id)
                ->first();

            if ($otraTaquilla) {
                $before = [
                    'active' => $otraTaquilla->active,
                    'mac_address' => $otraTaquilla->mac_address,
                    'device_fingerprint' => $otraTaquilla->device_fingerprint,
                ];

                $otraTaquilla->update(['active' => false, 'mac_address' => null, 'device_fingerprint' => null]);

                $recorder->record(AuditAction::TaquillaMacReassigned, $this->activationEvent(
                    action: AuditAction::TaquillaMacReassigned,
                    actorId: $taquilla->id,
                    actorLabel: 'taquilla:'.$taquilla->id,
                    scope: $scopeResolver->forModel($otraTaquilla),
                    entityId: $otraTaquilla->id,
                    before: $before,
                    after: [
                        'active' => $otraTaquilla->active,
                        'mac_address' => $otraTaquilla->mac_address,
                        'device_fingerprint' => $otraTaquilla->device_fingerprint,
                    ],
                    context: [
                        'reassigned_to_taquilla_id' => $taquilla->id,
                    ],
                ));

                $this->logActivacion(
                    $otraTaquilla->id,
                    $otraTaquilla->activation_code,
                    $mac,
                    false,
                    "MAC reasignada a taquilla ID {$taquilla->id} ({$taquilla->name})",
                    $redactor,
                );
            }

            $before = [
                'active' => $taquilla->active,
                'mac_address' => $taquilla->mac_address,
                'device_fingerprint' => $taquilla->device_fingerprint,
                'last_connection_at' => $taquilla->last_connection_at?->toIso8601String(),
            ];

            // Activar la taquilla solicitada
            $taquilla->update([
                'active' => true,
                'mac_address' => $mac,
                'device_fingerprint' => $fingerprint,
                'last_connection_at' => now(),
            ]);

            $recorder->record(AuditAction::TaquillaActivationSucceeded, $this->activationEvent(
                action: AuditAction::TaquillaActivationSucceeded,
                actorId: $taquilla->id,
                actorLabel: 'taquilla:'.$taquilla->id,
                scope: $scopeResolver->forModel($taquilla),
                entityId: $taquilla->id,
                before: $before,
                after: [
                    'active' => $taquilla->active,
                    'mac_address' => $taquilla->mac_address,
                    'device_fingerprint' => $taquilla->device_fingerprint,
                    'last_connection_at' => $taquilla->last_connection_at?->toIso8601String(),
                ],
                context: [
                    'mode' => 'activation',
                    'activation_code' => $codigo,
                ],
            ));

            $this->logActivacion($taquilla->id, $codigo, $mac, true, 'Activación exitosa', $redactor);

            return response()->json([
                'success' => true,
                'message' => 'Taquilla activada exitosamente.',
                'data' => [
                    'taquilla_id' => $taquilla->id,
                    'name' => $taquilla->name,
                    'code' => $taquilla->code,
                    'mac_address' => $taquilla->mac_address,
                    'active' => true,
                ],
            ]);
        });
    }

    /**
     * Build the device-actor envelope shared by every activation branch.
     * The recorder derives the entity type from the action and redacts the
     * activation code, MAC and fingerprint present in `context`/`before`/`after`.
     */
    private function activationEvent(
        AuditAction $action,
        ?int $actorId,
        string $actorLabel,
        ?int $entityId,
        array $context,
        ?array $before = null,
        ?array $after = null,
        ?AuditScope $scope = null,
    ): AuditEventData {
        return new AuditEventData(
            action: $action,
            actorType: AuditActorType::Device,
            actorId: $actorId,
            actorLabel: $actorLabel,
            actorRoles: [],
            scope: $scope,
            entityId: $entityId,
            before: $before,
            after: $after,
            context: $context,
        );
    }

    /**
     * Registrar log de activación en tabla logs (operativo, no auditoría).
     * `details` se guarda como array para que el cast JSON no lo codifique dos veces.
     */
    private function logActivacion(
        ?int $taquillaId,
        string $codigo,
        string $mac,
        bool $exitoso,
        string $motivo,
        AuditRedactor $redactor,
    ): void {
        Log::create([
            'user_id' => null,
            'action' => 'activacion_taquilla',
            'details' => $redactor->redact([
                'taquilla_id' => $taquillaId,
                'activation_code' => $codigo,
                'mac_address' => $mac,
                'exitoso' => $exitoso,
                'motivo' => $motivo,
            ]),
            'ip' => request()->ip() ?? 'local',
            'user_agent' => request()->header('User-Agent') ?? 'unknown',
        ]);
    }
}
