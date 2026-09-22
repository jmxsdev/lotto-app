<?php

namespace App\Http\Controllers\Api;

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
    public function ventanaVencimiento(ConfiguracionService $configuracion): JsonResponse
    {
        return response()->json($configuracion->ventanaVencimientoSinResultado());
    }

    public function actualizarVentanaVencimiento(Request $request, ConfiguracionService $configuracion): JsonResponse
    {
        $validated = $request->validate([
            'horas' => 'required|integer|min:1|max:8760',
        ]);

        $configuracion->setVentanaVencimiento((int) $validated['horas']);

        return response()->json($configuracion->ventanaVencimientoSinResultado());
    }
}
