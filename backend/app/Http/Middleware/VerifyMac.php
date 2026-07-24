<?php

namespace App\Http\Middleware;

use App\Models\Taquilla;
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
                'message' => 'Usuario sin taquilla asociada.',
            ], 403);
        }

        // Obtener la taquilla del usuario
        $taquilla = Taquilla::where('id', $user->taquilla_id)->first();

        if (!$taquilla) {
            return response()->json([
                'message' => 'Taquilla no encontrada.',
            ], 403);
        }

        // Verificar que la taquilla esté activa
        if (!$taquilla->active) {
            return response()->json([
                'message' => 'La taquilla está desactivada.',
            ], 403);
        }

        // Comparar MAC del header con MAC registrada
        if ($taquilla->mac_address !== $mac) {
            return response()->json([
                'message' => 'MAC address no coincide con la taquilla registrada.',
            ], 403);
        }

        // Actualizar last_connection_at
        $taquilla->update(['last_connection_at' => now()]);

        return $next($request);
    }
}
