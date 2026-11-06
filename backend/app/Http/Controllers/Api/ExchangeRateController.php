<?php

namespace App\Http\Controllers\Api;

use App\Audit\AuditAction;
use App\Audit\AuditEventData;
use App\Audit\AuditReason;
use App\Audit\AuditRecorder;
use App\Audit\AuditScope;
use App\Exceptions\AuditValidationException;
use App\Exceptions\AuditWriteException;
use App\Http\Controllers\Controller;
use App\Jobs\ScrapeExchangeRateJob;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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

        if (! $rate) {
            return response()->json(['message' => 'No hay tasa activa configurada.'], 404);
        }

        return response()->json([
            'rate' => $rate->rate,
            'base_currency' => $rate->base_currency,
            'updated_at' => $rate->reference_date,
            'notes' => $rate->notes,
        ]);
    }

    /** Crear una tasa y, opcionalmente, activarla; creación, desactivación y evidencia P0 comparten transacción. */
    public function store(Request $request, AuditRecorder $recorder)
    {
        $validator = Validator::make($request->all(), [
            'rate' => 'required|numeric|min:0',
            'base_currency' => 'sometimes|string|max:10',
            'reference_date' => 'sometimes|date',
            'notes' => 'nullable|string|max:255',
            'set_active' => 'sometimes|boolean',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Si no se envía set_active, por defecto no se activa
        $setActive = $request->input('set_active', false);

        $reasonRaw = $request->input('reason_code');
        $reason = is_string($reasonRaw) ? AuditReason::tryFrom($reasonRaw) : null;
        $reasonTextRaw = $request->input('reason_text');
        $reasonText = is_string($reasonTextRaw) ? $reasonTextRaw : null;

        try {
            $rate = DB::transaction(function () use ($request, $recorder, $setActive, $reason, $reasonText): ExchangeRate {
                $previousActiveId = null;
                $deactivatedCount = 0;

                // Si se va a activar, desactivar todas las demás
                if ($setActive) {
                    [$previousActiveId, $deactivatedCount] = $this->deactivateActiveRates();
                }

                $rate = ExchangeRate::create([
                    'rate' => $request->rate,
                    'base_currency' => $request->base_currency ?? 'USD',
                    'reference_date' => $request->reference_date ?? now(),
                    'set_by' => $request->user()->id,
                    'notes' => $request->notes,
                    'is_active' => $setActive,
                ]);

                $created = (new AuditEventData(
                    action: AuditAction::ExchangeRateCreated,
                    entityType: 'exchange_rate',
                    entityId: (int) $rate->id,
                    scope: new AuditScope,
                    after: $this->auditState($rate),
                ))->withReason($reason, $reasonText);

                $recorder->record(AuditAction::ExchangeRateCreated, $created);

                if ($setActive) {
                    $this->recordActivation($recorder, $rate, false, $previousActiveId, $deactivatedCount, $reason, $reasonText);
                }

                return $rate;
            });

            return response()->json($rate->load('setBy'), 201);
        } catch (AuditWriteException|AuditValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al guardar la tasa: '.$e->getMessage()], 500);
        }
    }

    /**
     * Mostrar una tasa específica
     */
    public function show(ExchangeRate $exchangeRate)
    {
        return response()->json($exchangeRate->load('setBy'));
    }

    /** Actualizar campos no críticos o alternar activación; `updated`/`activated` comparten transacción. */
    public function update(Request $request, ExchangeRate $exchangeRate, AuditRecorder $recorder)
    {
        $validator = Validator::make($request->all(), [
            'rate' => 'sometimes|numeric|min:0',
            'notes' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
            'reason_code' => 'nullable|string|max:64',
            'reason_text' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $reasonRaw = $request->input('reason_code');
        $reason = is_string($reasonRaw) ? AuditReason::tryFrom($reasonRaw) : null;
        $reasonTextRaw = $request->input('reason_text');
        $reasonText = is_string($reasonTextRaw) ? $reasonTextRaw : null;

        // El controlador original solo desactivaba el resto con un `true`
        // estricto; se conserva ese contrato.
        $activateRequested = $request->has('is_active') && $request->is_active === true;
        $wasActive = (bool) $exchangeRate->is_active;
        $beforeSnapshot = $this->auditState($exchangeRate);

        try {
            DB::transaction(function () use (
                $request,
                $exchangeRate,
                $recorder,
                $activateRequested,
                $wasActive,
                $beforeSnapshot,
                $reason,
                $reasonText
            ): void {
                $previousActiveId = null;
                $deactivatedCount = 0;

                // Si se va a activar, desactivar todas las demás
                if ($activateRequested) {
                    [$previousActiveId, $deactivatedCount] = $this->deactivateActiveRates((int) $exchangeRate->id);
                }

                $exchangeRate->update($request->only(['rate', 'notes', 'is_active']));

                $changedKeys = array_values(array_intersect(
                    array_keys($exchangeRate->getChanges()),
                    array_keys($beforeSnapshot),
                ));

                if ($changedKeys !== []) {
                    $updated = (new AuditEventData(
                        action: AuditAction::ExchangeRateUpdated,
                        entityType: 'exchange_rate',
                        entityId: (int) $exchangeRate->id,
                        scope: new AuditScope,
                        before: Arr::only($beforeSnapshot, $changedKeys),
                        after: Arr::only($this->auditState($exchangeRate), $changedKeys),
                    ))->withReason($reason, $reasonText);

                    $recorder->record(AuditAction::ExchangeRateUpdated, $updated);
                }

                if ($activateRequested && ! $wasActive) {
                    $this->recordActivation($recorder, $exchangeRate, false, $previousActiveId, $deactivatedCount, $reason, $reasonText);
                }
            });

            return response()->json($exchangeRate->load('setBy'));
        } catch (AuditWriteException|AuditValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al actualizar la tasa: '.$e->getMessage()], 500);
        }
    }

    /** Activar una tasa (desactiva las demás); activación y evidencia P0 comparten transacción. */
    public function setActive(Request $request, ExchangeRate $exchangeRate, AuditRecorder $recorder)
    {
        $reasonRaw = $request->input('reason_code');
        $reason = is_string($reasonRaw) ? AuditReason::tryFrom($reasonRaw) : null;
        $reasonTextRaw = $request->input('reason_text');
        $reasonText = is_string($reasonTextRaw) ? $reasonTextRaw : null;

        $wasActive = (bool) $exchangeRate->is_active;

        try {
            DB::transaction(function () use ($exchangeRate, $recorder, $wasActive, $reason, $reasonText): void {
                [$previousActiveId, $deactivatedCount] = $this->deactivateActiveRates((int) $exchangeRate->id);

                $exchangeRate->update(['is_active' => true]);

                $this->recordActivation($recorder, $exchangeRate, $wasActive, $previousActiveId, $deactivatedCount, $reason, $reasonText);
            });

            return response()->json($exchangeRate->load('setBy'));
        } catch (AuditWriteException|AuditValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al activar la tasa: '.$e->getMessage()], 500);
        }
    }

    /**
     * Disparar el scraper de BCV para obtener la tasa actual
     */
    public function scrape()
    {
        $job = new ScrapeExchangeRateJob;
        $job->handle();

        $tasa = ExchangeRate::where('is_active', true)->latest()->first();

        if (! $tasa) {
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

    /** @return array<string, mixed> Snapshot seguro de la tasa para `before`/`after` (sin timestamps). */
    private function auditState(ExchangeRate $rate): array
    {
        return [
            'rate' => $rate->rate,
            'base_currency' => $rate->base_currency,
            'reference_date' => $rate->reference_date?->toIso8601String(),
            'set_by' => $rate->set_by,
            'notes' => $rate->notes,
            'is_active' => (bool) $rate->is_active,
        ];
    }

    /** @return array{0: int|null, 1: int} Desactiva las tasas activas; reporta id previo y conteo. */
    private function deactivateActiveRates(?int $exceptId = null): array
    {
        $query = ExchangeRate::query()->where('is_active', true);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $previousActiveId = (clone $query)->orderByDesc('id')->value('id');
        $deactivatedCount = (int) (clone $query)->update(['is_active' => false]);

        return [$previousActiveId !== null ? (int) $previousActiveId : null, $deactivatedCount];
    }

    /** Emitir `exchange_rate.activated` con el diff false→true y el contexto de reemplazo. */
    private function recordActivation(
        AuditRecorder $recorder,
        ExchangeRate $rate,
        bool $beforeActive,
        ?int $previousActiveId,
        int $deactivatedCount,
        ?AuditReason $reason,
        ?string $reasonText,
    ): void {
        $context = ['deactivated_count' => $deactivatedCount];

        if ($previousActiveId !== null) {
            $context['previous_active_id'] = $previousActiveId;
        }

        $event = (new AuditEventData(
            action: AuditAction::ExchangeRateActivated,
            entityType: 'exchange_rate',
            entityId: (int) $rate->id,
            scope: new AuditScope,
            before: ['is_active' => $beforeActive],
            after: ['is_active' => true],
            context: $context,
        ))->withReason($reason, $reasonText);

        $recorder->record(AuditAction::ExchangeRateActivated, $event);
    }
}
