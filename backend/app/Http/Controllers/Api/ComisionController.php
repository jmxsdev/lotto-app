<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComisionDefault;
use App\Models\User;
use App\Services\ComisionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ComisionController extends Controller
{
    public function __construct(private ComisionService $comisiones) {}

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
            'defaults.*.porcentaje_pago' => ['nullable', 'numeric', 'min:0', 'max:16'],
        ], [
            'defaults.*.porcentaje_pago.max' => 'El % de pago global no puede superar 16%.',
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

    /**
     * Consulta read-only de comisiones por rango (Modelo A).
     *
     * GET /api/v1/comisiones/consulta — super_master|master (sin
     * manage_comisiones). Valida desde/hasta (date, hasta >= desde) → 422;
     * el super_master consulta global o filtra por `banca_ids`; el master
     * solo sus bancas (masterBancaIds), y `banca_ids` fuera de alcance ⇒
     * 403. No persiste nada ni expone estado/conflictos del ledger.
     */
    public function consulta(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'banca_ids' => ['nullable', 'array'],
            'banca_ids.*' => ['integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $bancaIds = $request->input('banca_ids');
        $permitidas = $this->bancasEnAlcance($user);

        if ($permitidas !== null) {
            if ($bancaIds !== null) {
                $foraneas = array_diff($bancaIds, $permitidas);

                if ($foraneas !== []) {
                    return response()->json(['message' => 'No autorizado: banca_ids fuera de su alcance.'], 403);
                }
            } else {
                $bancaIds = $permitidas;
            }
        }

        $desde = Carbon::parse($request->input('desde'));
        $hasta = Carbon::parse($request->input('hasta'));

        $rows = $this->comisiones->filasPorRango($desde, $hasta, $bancaIds);

        $data = array_map(fn (array $fila) => [
            'nivel' => $fila['nivel'],
            'entidad_id' => $fila['entidad_id'],
            'entidad' => $fila['entidad'],
            'monto_comision' => number_format((float) $fila['monto_comision'], 2, '.', ''),
        ], $rows);

        return response()->json([
            'data' => $data,
            'meta' => [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
                'total' => count($data),
            ],
        ]);
    }

    /**
     * Bancas visibles para el usuario en la consulta.
     *
     * super_master ⇒ null (sin restricción, alcance global); master ⇒ ids
     * de las bancas que administra (posiblemente vacío, nunca global).
     *
     * @return array<int, int>|null
     */
    private function bancasEnAlcance(User $user): ?array
    {
        if ($user->role === 'master') {
            return $user->masterBancaIds()->all();
        }

        return null;
    }
}
