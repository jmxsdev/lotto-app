<?php

namespace App\Http\Controllers\Api;

use App\Audit\AuditAction;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditRecorder;
use App\Audit\AuditScope;
use App\Http\Controllers\Controller;
use App\Services\ConfiguracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración de la ventana de vencimiento sin resultado (REQ13/D5, §5).
 *
 * GET/PUT /api/v1/configuraciones/apuestas-vencimiento, restringido a los
 * roles super_master y master por el middleware `role:` de la ruta. GET
 * devuelve el valor con fallback 24 h; PUT persiste `{"horas": N}` en la
 * tabla `configuraciones` (clave global `apuestas.vencimiento_sin_resultado`).
 */
class ConfiguracionController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function ventanaVencimiento(ConfiguracionService $configuracion): JsonResponse
    {
        return response()->json($configuracion->ventanaVencimientoSinResultado());
    }

    public function actualizarVentanaVencimiento(Request $request, ConfiguracionService $configuracion): JsonResponse
    {
        $validated = $request->validate([
            'horas' => 'required|integer|min:1|max:8760',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $antes = $configuracion->ventanaVencimientoSinResultado();

        $configuracion->setVentanaVencimiento((int) $validated['horas']);

        $despues = $configuracion->ventanaVencimientoSinResultado();

        $reasonRaw = $request->input('reason_code');
        $reason = is_string($reasonRaw) ? AuditReason::tryFrom($reasonRaw) : null;
        $reasonTextRaw = $request->input('reason_text');
        $reasonText = is_string($reasonTextRaw) ? $reasonTextRaw : null;

        // P1 best-effort: una caída de auditoría no bloquea la actualización
        // de la configuración ya persistida.
        $event = (new AuditEventData(
            action: AuditAction::ConfigBetExpirationWindowUpdated,
            entityType: 'configuracion',
            entityId: null,
            scope: new AuditScope,
            before: ['horas' => $antes['horas']],
            after: ['horas' => $despues['horas']],
        ))->withReason($reason, $reasonText);

        $this->audit->record(AuditAction::ConfigBetExpirationWindowUpdated, $event);

        return response()->json($configuracion->ventanaVencimientoSinResultado());
    }
}
