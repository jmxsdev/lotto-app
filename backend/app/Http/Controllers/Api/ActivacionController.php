<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Taquilla;
use App\Models\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ActivacionController extends Controller
{
    /**
     * Activar una taquilla con código y MAC address
     * Endpoint público (sin autenticación)
     */
    public function activar(Request $request)
    {
        // Validar input
        $validator = Validator::make($request->all(), [
            'activation_code' => 'required|string|max:32',
            'mac_address' => 'required|string|regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
        ], [
            'activation_code.required' => 'El código de activación es obligatorio.',
            'mac_address.required' => 'La dirección MAC es obligatoria.',
            'mac_address.regex' => 'El formato de la dirección MAC no es válido (ej: AA:BB:CC:DD:EE:FF).',
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

        // Buscar taquilla por activation_code
        $taquilla = Taquilla::where('activation_code', $codigo)->first();

        if (!$taquilla) {
            $this->logActivacion(null, $codigo, $mac, false, 'Código de activación no encontrado');
            
            return response()->json([
                'success' => false,
                'message' => 'Código de activación inválido o expirado.',
            ], 404);
        }

        // Verificar si ya está activa con esta misma MAC
        if ($taquilla->active && $taquilla->mac_address === $mac) {
            $taquilla->update(['last_connection_at' => now()]);
            
            $this->logActivacion($taquilla->id, $codigo, $mac, true, 'Reactivación - misma MAC');
            
            return response()->json([
                'success' => true,
                'message' => 'Taquilla reactivada exitosamente.',
                'data' => [
                    'taquilla_id' => $taquilla->id,
                    'name' => $taquilla->name,
                    'code' => $taquilla->code,
                    'mac_address' => $taquilla->mac_address,
                    'active' => true,
                ]
            ]);
        }

        // Si ya está activa con otra MAC, rechazar (evitar hijacking)
        if ($taquilla->active && $taquilla->mac_address !== $mac) {
            $this->logActivacion($taquilla->id, $codigo, $mac, false, 'Intento de activación con MAC diferente');

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
            $otraTaquilla->update(['active' => false, 'mac_address' => null]);
            
            $this->logActivacion(
                $otraTaquilla->id,
                $otraTaquilla->activation_code,
                $mac,
                false,
                "MAC reasignada a taquilla ID {$taquilla->id} ({$taquilla->name})"
            );
        }

        // Activar la taquilla solicitada
        $taquilla->update([
            'active' => true,
            'mac_address' => $mac,
            'last_connection_at' => now(),
        ]);

        $this->logActivacion($taquilla->id, $codigo, $mac, true, 'Activación exitosa');

        return response()->json([
            'success' => true,
            'message' => 'Taquilla activada exitosamente.',
            'data' => [
                'taquilla_id' => $taquilla->id,
                'name' => $taquilla->name,
                'code' => $taquilla->code,
                'mac_address' => $taquilla->mac_address,
                'active' => true,
            ]
        ]);
    }

    /**
     * Registrar log de activación en tabla logs
     */
    private function logActivacion(?int $taquillaId, string $codigo, string $mac, bool $exitoso, string $motivo): void
    {
        Log::create([
            'user_id' => null,
            'action' => 'activacion_taquilla',
            'details' => json_encode([
                'taquilla_id' => $taquillaId,
                'codigo' => $codigo,
                'mac' => $mac,
                'exitoso' => $exitoso,
                'motivo' => $motivo,
            ]),
            'ip' => request()->ip() ?? 'local',
            'user_agent' => request()->header('User-Agent') ?? 'unknown',
        ]);
    }
}
