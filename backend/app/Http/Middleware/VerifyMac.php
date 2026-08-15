<?php

namespace App\Http\Middleware;

use App\Models\Taquilla;
use App\Services\ActivacionEfectivaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMac
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Solo verificar MAC para usuarios con rol de taquilla
        $user = $request->user();

        if (!$user || !$user->hasRole('taquilla')) {
            return $next($request);
        }

        // Obtener MAC del header X-Device-MAC
        $mac = $request->header('X-Device-MAC');

        if (!$mac) {
            return response()->json([
                'message' => 'MAC address no proporcionada.',
            ], 403);
        }

        // Normalizar MAC
        $mac = strtoupper(trim($mac));

        // Validar formato MAC básico
        if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac)) {
            return response()->json([
                'message' => 'Formato de MAC address inválido.',
            ], 403);
        }

        // Verificar que usuario tenga taquilla asociada
        if (!$user->taquilla_id) {
            return response()->json([
                'message' => 'Usuario sin agencia asociada.',
            ], 403);
        }

        // Obtener la taquilla del usuario (con su cadena para activación efectiva)
        $taquilla = Taquilla::with('grupo.banca')->where('id', $user->taquilla_id)->first();

        if (!$taquilla) {
            return response()->json([
                'message' => 'Agencia no encontrada.',
            ], 403);
        }

        // Verificar la activación efectiva: propia + grupo + banca (sin escrituras en cascada)
        $estado = app(ActivacionEfectivaService::class)->estadoTaquilla($taquilla);

        if (!$estado['active']) {
            return response()->json([
                'message' => match ($estado['causa']) {
                    'grupo' => 'La agencia está pausada porque su grupo está desactivado.',
                    'banca' => 'La agencia está pausada porque su banca está desactivada.',
                    default => 'La agencia está desactivada.',
                },
            ], 403);
        }

        // Comparar MAC del header con MAC registrada
        if ($taquilla->mac_address !== $mac) {
            return response()->json([
                'message' => 'MAC address no coincide con la agencia registrada.',
            ], 403);
        }

        $fingerprint = $request->header('X-Device-Fingerprint');
        if ($taquilla->device_fingerprint && (!$fingerprint || $fingerprint !== $taquilla->device_fingerprint)) {
            return response()->json([
                'message' => 'Huella digital del dispositivo no coincide.',
            ], 403);
        }

        // Actualizar last_connection_at
        $taquilla->update(['last_connection_at' => now()]);

        return $next($request);
    }
}
