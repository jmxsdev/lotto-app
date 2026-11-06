<?php

namespace App\Http\Controllers\Api;

use App\Audit\AuditAction;
use App\Audit\AuditActorType;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use App\Audit\AuditRedactor;
use App\Http\Controllers\Controller;
use App\Models\Taquilla;
use Illuminate\Http\Request;

class DispositivoController extends Controller
{
    public function verificar(Request $request, AuditRecorder $recorder, AuditRedactor $redactor)
    {
        $request->validate([
            'device_fingerprint' => 'required|string|max:255',
        ]);

        $fingerprint = $request->input('device_fingerprint');

        $taquilla = Taquilla::where('device_fingerprint', $fingerprint)
            ->where('active', true)
            ->first();

        if ($taquilla) {
            return response()->json([
                'status' => 'active',
                'taquilla_name' => $taquilla->name,
                'message' => 'Dispositivo registrado. Puede iniciar sesión.',
            ]);
        }

        // P1 best-effort: la verificación negativa es evidencia útil, pero su
        // escritura nunca bloquea la respuesta al dispositivo.
        $hashedFingerprint = (string) $redactor->hashFingerprint($fingerprint);

        $recorder->record(AuditAction::DeviceVerificationFailed, new AuditEventData(
            action: AuditAction::DeviceVerificationFailed,
            actorType: AuditActorType::Device,
            actorLabel: mb_substr('device:'.$hashedFingerprint, 0, 191),
            actorRoles: [],
            context: [
                'device_fingerprint' => $fingerprint,
                'failure_reason' => 'device_not_registered',
            ],
        ));

        return response()->json([
            'status' => 'pending',
            'taquilla_name' => null,
            'message' => 'Dispositivo no registrado. Active su taquilla.',
        ]);
    }
}
