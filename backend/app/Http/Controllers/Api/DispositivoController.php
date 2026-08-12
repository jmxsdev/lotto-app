<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Taquilla;
use Illuminate\Http\Request;

class DispositivoController extends Controller
{
    public function verificar(Request $request)
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

        return response()->json([
            'status' => 'pending',
            'taquilla_name' => null,
            'message' => 'Dispositivo no registrado. Active su agencia.',
        ]);
    }
}
