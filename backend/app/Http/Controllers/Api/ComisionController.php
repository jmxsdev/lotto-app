<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComisionDefault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ComisionController extends Controller
{
    /**
     * Listar el default global de comisión (2 filas: bs y usd).
     *
     * GET /api/v1/comisiones/defaults — solo super_master.
     */
    public function defaults()
    {
        $defaults = ComisionDefault::orderBy('moneda')->get();

        return response()->json($defaults);
    }

    /**
     * Sobrescribir el default global de comisión por moneda.
     *
     * PUT /api/v1/comisiones/defaults — super_master + manage_comisiones.
     * Recibe `defaults` como array de ítems {moneda, porcentaje_pago};
     * cada moneda se actualiza (upsert) de forma independiente.
     */
    public function updateDefaults(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'defaults' => ['required', 'array', 'min:1', 'max:2'],
            'defaults.*.moneda' => ['required', 'distinct', Rule::in(['bs', 'usd'])],
            'defaults.*.porcentaje_pago' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        foreach ($request->input('defaults') as $item) {
            ComisionDefault::updateOrCreate(
                ['moneda' => $item['moneda']],
                ['porcentaje_pago' => $item['porcentaje_pago'] ?? null]
            );
        }

        return response()->json(ComisionDefault::orderBy('moneda')->get());
    }
}
