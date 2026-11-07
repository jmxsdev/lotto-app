<?php

namespace App\Jobs;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use App\Audit\AuditScopeResolver;
use App\Models\Apuesta;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireUnclaimedPrizesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     *
     * Encuentra tickets con estado='ganador' cuya vigencia efectiva de premios
     * ha expirado y los marca como 'vencido'.
     *
     * Vigencia efectiva: COALESCE(taquilla.vigencia_premios, grupo.vigencia_premios, banca.vigencia_premios)
     * NULL = nunca expira — esos tickets son ignorados por el WHERE IS NOT NULL.
     *
     * Idempotente: WHERE tickets.estado='ganador' garantiza que tickets ya vencidos
     * no se reprocesan.
     */
    public function handle(): void
    {
        Log::info('=== INICIO ExpireUnclaimedPrizesJob ===');

        $context = app(AuditContext::class);
        $context->reset();
        $context->forSystem(
            'job:ExpireUnclaimedPrizesJob',
            $this->jobId(),
            $this->queueName(),
            'job',
        );

        try {
            $this->execute();
        } finally {
            $context->reset();
        }

        Log::info('=== FIN ExpireUnclaimedPrizesJob ===');
    }

    private function execute(): void
    {
        // Query unificada: resuelve la vigencia efectiva con COALESCE en SQL,
        // luego compara la antigüedad del ticket en PHP para ser compatible con
        // SQLite (testing) y MySQL (producción) sin depender de INTERVAL syntax.
        $candidatos = DB::table('tickets')
            ->join('taquillas', 'tickets.taquilla_id', '=', 'taquillas.id')
            ->join('grupos', 'taquillas.grupo_id', '=', 'grupos.id')
            ->join('bancas', 'grupos.banca_id', '=', 'bancas.id')
            ->where('tickets.estado', 'ganador')
            ->whereRaw(
                'COALESCE(taquillas.vigencia_premios, grupos.vigencia_premios, bancas.vigencia_premios) IS NOT NULL'
            )
            ->select(
                'tickets.id',
                'tickets.created_at',
                DB::raw(
                    'COALESCE(taquillas.vigencia_premios, grupos.vigencia_premios, bancas.vigencia_premios) as vigencia_efectiva'
                )
            )
            ->get();

        $expirados = 0;

        foreach ($candidatos as $candidato) {
            $vigencia = (int) $candidato->vigencia_efectiva;
            $fechaLimite = now()->subDays($vigencia);
            $createdAt = Carbon::parse($candidato->created_at);

            // Solo expira si la fecha de creación es anterior al límite
            if (! $createdAt->lt($fechaLimite)) {
                continue;
            }

            // P0 fail-closed: una transacción por ticket. Si la auditoría
            // falla, ticket y apuestas quedan intactos para el próximo ciclo.
            $expirados += DB::transaction(function () use ($candidato): int {
                // Doble verificación: solo si aún está en estado 'ganador'
                $ticket = Ticket::where('id', $candidato->id)
                    ->where('estado', 'ganador')
                    ->lockForUpdate()
                    ->first();

                if (! $ticket) {
                    return 0;
                }

                $apuestaIds = Apuesta::where('ticket_id', $ticket->id)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                $ticket->update(['estado' => 'vencido']);

                // Marcar también todas las apuestas del ticket como vencidas
                Apuesta::where('ticket_id', $ticket->id)
                    ->update(['estado' => 'vencido']);

                // WU-8a: el ticket es la unidad auditada aquí; `bet.expired`
                // pertenece a MarcarApuestasVencidasJob, donde la apuesta es
                // la unidad de transición.
                app(AuditRecorder::class)->critical(
                    AuditAction::TicketExpired,
                    new AuditEventData(
                        action: AuditAction::TicketExpired,
                        entityType: 'ticket',
                        entityId: (int) $ticket->id,
                        scope: app(AuditScopeResolver::class)->forModel($ticket),
                        before: ['estado' => 'ganador'],
                        after: ['estado' => 'vencido'],
                        context: [
                            'apuestas' => $apuestaIds,
                            'apuestas_count' => count($apuestaIds),
                        ],
                    ),
                );

                return 1;
            });
        }

        Log::info("ExpireUnclaimedPrizesJob: {$expirados} tickets marcados como vencido.");
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
