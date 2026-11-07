<?php

namespace App\Jobs;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use App\Audit\AuditScopeResolver;
use App\Models\Apuesta;
use App\Services\ConfiguracionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vencimiento de apuestas sin resultado (REQ13/D5, design §5/§6).
 *
 * Job DIARIO distinto de `ExpireUnclaimedPrizesJob` (ese expira tickets
 * 'ganador' por vigencia de premios; este vence apuestas 'pendiente' cuyo
 * resultado nunca llegó, tras la ventana configurable de 24 h por defecto).
 *
 * Garantía de búsqueda antes de vencer: agrupa las candidatas por
 * (juego_id, fecha_sorteo) y relanza `ScrapeResultsJob` para cada par
 * (catch-up por fecha; el job existente reintenta 3× con backoff 300 s y hace
 * upsert idempotente). Si el resultado aparece, la cadena liquida
 * (`verificarGanadores` → ganadora/perdida). Solo las apuestas que siguen
 * `pendiente` sin `resultado_id` tras la ventana y el reintento pasan a
 * `vencido`. Idempotente: la consulta candidata filtra estado + resultado_id.
 *
 * Tripleta multi-sorteo: el settlement de la última extracción del día la
 * marca `perdida` (ya fue evaluada). Este job solo la verá si el último
 * resultado nunca llegó; en ese caso `vencido` es el estado correcto porque
 * no hay evidencia para liquidar.
 */
class MarcarApuestasVencidasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $ventanaHoras = app(ConfiguracionService::class)->horasVencimientoSinResultado();
        $limite = now()->subHours($ventanaHoras);

        Log::info("=== INICIO MarcarApuestasVencidasJob (ventana: {$ventanaHoras} h) ===");

        $context = app(AuditContext::class);
        $context->reset();
        $context->forSystem(
            'job:MarcarApuestasVencidasJob',
            $this->jobId(),
            $this->queueName(),
            'job',
        );

        try {
            $this->execute($limite);
        } finally {
            $context->reset();
        }

        Log::info('=== FIN MarcarApuestasVencidasJob ===');
    }

    private function execute(\DateTimeInterface $limite): void
    {
        $candidatas = Apuesta::query()
            ->where('estado', 'pendiente')
            ->whereNull('resultado_id')
            ->where('sorteo_hora', '<', $limite)
            ->get(['id', 'juego_id', 'sorteo_hora']);

        if ($candidatas->isEmpty()) {
            Log::info('MarcarApuestasVencidasJob: sin candidatas.');

            return;
        }

        // Garantía de búsqueda (D5): UNA búsqueda por par (juego, fecha).
        $pares = $candidatas->groupBy(fn (Apuesta $a) => $a->juego_id.'|'.$a->sorteo_hora->toDateString());

        foreach ($pares as $clave => $grupo) {
            [$juegoId, $fecha] = explode('|', $clave);

            try {
                // ScrapeResultsJob: reintenta 3× con backoff 300 s y hace
                // upsert idempotente; al guardar, liquida con verificarGanadores.
                ScrapeResultsJob::dispatchSync((int) $juegoId, $fecha);
            } catch (\Throwable $e) {
                Log::warning("MarcarApuestasVencidasJob: catch-up falló para juego {$juegoId} fecha {$fecha}: ".$e->getMessage());
            }
        }

        // `ScrapeResultsJob::dispatchSync` puede resetear el contexto
        // compartido; se restaura el actor system antes de auditar.
        $context = app(AuditContext::class);
        $context->reset();
        $context->forSystem(
            'job:MarcarApuestasVencidasJob',
            $this->jobId(),
            $this->queueName(),
            'job',
        );

        // Tras el reintento: solo vence lo que SIGUE pendiente sin resultado.
        $aVencer = Apuesta::query()
            ->whereIn('id', $candidatas->pluck('id'))
            ->where('estado', 'pendiente')
            ->whereNull('resultado_id')
            ->where('sorteo_hora', '<', $limite)
            ->pluck('id');

        $vencidas = 0;

        // P0 fail-closed por apuesta: si la auditoría falla, esa apuesta
        // vuelve a `pendiente` y el job falla; las siguientes quedan para
        // el próximo ciclo.
        foreach ($aVencer as $apuestaId) {
            $vencidas += DB::transaction(function () use ($apuestaId, $limite): int {
                $apuesta = Apuesta::query()
                    ->where('id', $apuestaId)
                    ->where('estado', 'pendiente')
                    ->whereNull('resultado_id')
                    ->where('sorteo_hora', '<', $limite)
                    ->lockForUpdate()
                    ->first();

                if (! $apuesta) {
                    return 0;
                }

                $apuesta->update(['estado' => 'vencido']);

                app(AuditRecorder::class)->critical(
                    AuditAction::BetExpired,
                    new AuditEventData(
                        action: AuditAction::BetExpired,
                        entityType: 'apuesta',
                        entityId: (int) $apuesta->id,
                        scope: app(AuditScopeResolver::class)->forModel($apuesta),
                        before: ['estado' => 'pendiente'],
                        after: ['estado' => 'vencido'],
                        context: ['juego_id' => (int) $apuesta->juego_id],
                    ),
                );

                return 1;
            });
        }

        Log::info("MarcarApuestasVencidasJob: {$vencidas} apuestas marcadas como vencido tras {$pares->count()} reintentos de búsqueda.");
    }

    private function jobId(): ?string
    {
        $id = $this->job?->getJobId();

        if ($id === null) {
            return null;
        }

        $id = (string) $id;

        return $id !== '' ? $id : null;
    }

    private function queueName(): ?string
    {
        $queue = $this->job?->getQueue() ?? $this->queue;

        return is_string($queue) && $queue !== '' ? $queue : null;
    }
}
