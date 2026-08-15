<?php

namespace App\Jobs;

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
            if (!$createdAt->lt($fechaLimite)) {
                continue;
            }

            DB::transaction(function () use ($candidato, &$expirados) {
                // Doble verificación: solo si aún está en estado 'ganador'
                $actualizado = Ticket::where('id', $candidato->id)
                    ->where('estado', 'ganador')
                    ->update(['estado' => 'vencido']);

                if ($actualizado) {
                    // Marcar también todas las apuestas del ticket como vencidas
                    Apuesta::where('ticket_id', $candidato->id)
                        ->update(['estado' => 'vencido']);

                    $expirados++;
                }
            });
        }

        Log::info("ExpireUnclaimedPrizesJob: {$expirados} tickets marcados como vencido.");
        Log::info('=== FIN ExpireUnclaimedPrizesJob ===');
    }
}
