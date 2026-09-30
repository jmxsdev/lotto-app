<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comision;
use App\Models\ComisionDefault;
use App\Models\Grupo;
use App\Models\Log;
use App\Models\Taquilla;
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

    /**
     * Listar comisiones del ledger, paginadas (D8).
     *
     * GET /api/v1/comisiones — super_master|master. El super_master ve
     * todo; el master solo las filas de las bancas que administra
     * (masterBancaIds); sin bancas ⇒ lista vacía, nunca global.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Comision::query();

        if ($user->role === 'master') {
            $bancaIds = $user->masterBancaIds();

            if ($bancaIds->isEmpty()) {
                $query->whereRaw('1=0');
            } else {
                $query->where(function ($q) use ($bancaIds) {
                    $q->whereHas('grupo', fn ($g) => $g->whereIn('banca_id', $bancaIds))
                        ->orWhereHas('taquilla', fn ($t) => $t->whereHas('grupo', fn ($g) => $g->whereIn('banca_id', $bancaIds)));
                });
            }
        }

        return response()->json(
            $query->with(['grupo', 'taquilla'])
                ->latest('id')
                ->paginate((int) $request->input('per_page', 15))
        );
    }

    /**
     * Liquidar comisiones de un rango (D4/D7/D8).
     *
     * POST /api/v1/comisiones/liquidar — super_master|master +
     * manage_comisiones. El master liquida SOLO sus bancas: `banca_ids`
     * fuera de su alcance ⇒ 403; sin `banca_ids` ⇒ masterBancaIds().
     * Rango solapado con filas existentes ⇒ 422 con los ids en conflicto.
     */
    public function liquidar(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'banca_ids' => ['nullable', 'array'],
            'banca_ids.*' => ['integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $bancaIds = $request->input('banca_ids');

        if ($user->role === 'master') {
            $permitidas = $user->masterBancaIds();

            if ($bancaIds !== null) {
                $foraneas = array_diff($bancaIds, $permitidas->all());

                if ($foraneas !== []) {
                    return response()->json(['message' => 'No autorizado: banca_ids fuera de su alcance.'], 403);
                }
            } else {
                $bancaIds = $permitidas->all();
            }
        }

        $resultado = $this->comisiones->liquidar(
            Carbon::parse($request->input('desde')),
            Carbon::parse($request->input('hasta')),
            $bancaIds,
            $user->id
        );

        if ($resultado['conflictos'] !== []) {
            return response()->json([
                'message' => 'El rango se solapa con liquidaciones existentes. No se puede liquidar dos veces el mismo período.',
                'conflictos' => $resultado['conflictos'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Comisiones liquidadas correctamente.',
            'data' => $resultado['rows'],
        ], 201);
    }

    /**
     * Marcar una comisión como pagada (D5).
     *
     * PATCH /api/v1/comisiones/{comision}/pagar — super_master|master +
     * manage_comisiones; el master solo dentro de masterBancaIds (403).
     * pendiente→pagado; PATCH sobre una fila ya pagada = no-op idempotente.
     */
    public function pagar(Request $request, Comision $comision)
    {
        $user = $request->user();

        if ($user->role === 'master' && ! $this->comisionEnAlcanceMaster($user, $comision)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if ($comision->estado === 'pendiente') {
            $comision->update(['estado' => 'pagado']);

            Log::create([
                'user_id' => $user->id,
                'action' => 'comision_pagar',
                'details' => json_encode(['comision_id' => $comision->id]),
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ]);
        }

        return response()->json($comision->fresh());
    }

    /**
     * ¿La comisión pertenece a una banca administrada por este master?
     */
    private function comisionEnAlcanceMaster(User $user, Comision $comision): bool
    {
        $bancaIds = $user->masterBancaIds();

        if ($bancaIds->isEmpty()) {
            return false;
        }

        if ($comision->grupo_id !== null) {
            return Grupo::where('id', $comision->grupo_id)
                ->whereIn('banca_id', $bancaIds)
                ->exists();
        }

        if ($comision->taquilla_id !== null) {
            return Taquilla::where('id', $comision->taquilla_id)
                ->whereHas('grupo', fn ($g) => $g->whereIn('banca_id', $bancaIds))
                ->exists();
        }

        return false;
    }
}
