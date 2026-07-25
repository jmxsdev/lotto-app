<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Jobs\ScrapeExchangeRateJob;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class ExchangeRateController extends Controller
{
    /**
     * Listar todas las tasas (historial)
     */
    public function index(Request $request)
    {
        $rates = ExchangeRate::with('setBy')->orderBy('reference_date', 'desc')->get();
        return response()->json($rates);
    }

    /**
     * Obtener la tasa activa (pública)
     */
    public function active()
    {
        $rate = ExchangeRate::where('is_active', true)->latest('reference_date')->first();

        if (!$rate) {
            return response()->json(['message' => 'No hay tasa activa configurada.'], 404);
        }

        return response()->json([
            'rate' => $rate->rate,
            'base_currency' => $rate->base_currency,
            'updated_at' => $rate->reference_date,
            'notes' => $rate->notes,
        ]);
    }

    /**
     * Crear una nueva tasa (histórica) y opcionalmente activarla
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rate' => 'required|numeric|min:0',
            'base_currency' => 'sometimes|string|max:10',
            'reference_date' => 'sometimes|date',
            'notes' => 'nullable|string|max:255',
            'set_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Si no se envía set_active, por defecto no se activa
        $setActive = $request->input('set_active', false);

        // Iniciar transacción para asegurar que solo una tasa esté activa
        \DB::beginTransaction();
        try {
            // Si se va a activar, desactivar todas las demás
            if ($setActive) {
                ExchangeRate::where('is_active', true)->update(['is_active' => false]);
            }

            $rate = ExchangeRate::create([
                'rate' => $request->rate,
                'base_currency' => $request->base_currency ?? 'USD',
                'reference_date' => $request->reference_date ?? now(),
                'set_by' => $request->user()->id,
                'notes' => $request->notes,
                'is_active' => $setActive,
            ]);

            \DB::commit();

            return response()->json($rate->load('setBy'), 201);
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json(['message' => 'Error al guardar la tasa: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Mostrar una tasa específica
     */
    public function show(ExchangeRate $exchangeRate)
    {
        return response()->json($exchangeRate->load('setBy'));
    }

    /**
     * Actualizar una tasa (solo campos no críticos, o activar/desactivar)
     */
    public function update(Request $request, ExchangeRate $exchangeRate)
    {
        $validator = Validator::make($request->all(), [
            'rate' => 'sometimes|numeric|min:0',
            'notes' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Si se va a activar, desactivar todas las demás
        if ($request->has('is_active') && $request->is_active === true) {
            \DB::beginTransaction();
            try {
                ExchangeRate::where('is_active', true)->where('id', '!=', $exchangeRate->id)->update(['is_active' => false]);
                $exchangeRate->update($request->only(['rate', 'notes', 'is_active']));
                \DB::commit();
            } catch (\Exception $e) {
                \DB::rollBack();
                return response()->json(['message' => 'Error al actualizar la tasa: ' . $e->getMessage()], 500);
            }
        } else {
            $exchangeRate->update($request->only(['rate', 'notes', 'is_active']));
        }

        return response()->json($exchangeRate->load('setBy'));
    }

    /**
     * Activar una tasa específica (desactiva las demás)
     */
    public function setActive(Request $request, ExchangeRate $exchangeRate)
    {
        \DB::beginTransaction();
        try {
            ExchangeRate::where('is_active', true)->where('id', '!=', $exchangeRate->id)->update(['is_active' => false]);
            $exchangeRate->update(['is_active' => true]);
            \DB::commit();

            return response()->json($exchangeRate->load('setBy'));
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json(['message' => 'Error al activar la tasa: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Disparar el scraper de BCV para obtener la tasa actual
     */
    public function scrape()
    {
        $job = new ScrapeExchangeRateJob();
        $job->handle();

        $tasa = ExchangeRate::where('is_active', true)->latest()->first();

        if (!$tasa) {
            return response()->json(['message' => 'No se pudo obtener la tasa del BCV.'], 502);
        }

        return response()->json([
            'message' => 'Tasa obtenida del BCV correctamente.',
            'data' => [
                'rate' => $tasa->rate,
                'base_currency' => $tasa->base_currency,
                'reference_date' => $tasa->reference_date,
                'notes' => $tasa->notes,
            ],
        ]);
    }

}
