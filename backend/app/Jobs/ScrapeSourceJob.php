<?php

namespace App\Jobs;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEventData;
use App\Audit\AuditRecorder;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\Log;
use App\Models\Resultado;
use App\Services\ApuestaService;
use App\Services\ScraperSourceResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log as FacadeLog;

/**
 * Job por fuente de scrape: un solo fetch persiste todos los juegos que
 * comparten feed (familia lottoactivo), cada uno bajo su propio juego_id.
 *
 * Guarda: con `horaObjetivo` definida, ejecuta SOLO si al menos un miembro
 * programado a esa hora no tiene fila (juego, fecha, hora); si todos la
 * tienen, omite el fetch. Con `horaObjetivo` null (disparo manual) siempre
 * ejecuta.
 */
class ScrapeSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $sourceKey,
        public ?string $fecha = null,
        public ?string $horaObjetivo = null,
    ) {}

    public function handle(): void
    {
        $context = app(AuditContext::class);
        $context->reset();
        $context->forSystem(
            'job:ScrapeSourceJob',
            $this->jobId(),
            $this->queueName(),
            'job',
        );

        try {
            $this->execute();
        } finally {
            $context->reset();
        }
    }

    private function execute(): void
    {
        $fecha = $this->fecha ?? now()->format('Y-m-d');
        $resolver = app(ScraperSourceResolver::class);
        $fuente = $resolver->sourceByKey($this->sourceKey);

        if (! $fuente) {
            FacadeLog::warning("ScrapeSourceJob: fuente {$this->sourceKey} no encontrada");

            return;
        }

        if (! $fuente->scraperClass) {
            FacadeLog::warning("ScrapeSourceJob: la fuente {$this->sourceKey} no tiene scraper resoluble");
            $this->logToDatabase('warning', 'No existe scraper para la fuente', [
                'fuente' => $this->sourceKey,
            ]);

            return;
        }

        $miembros = Juego::whereIn('id', $fuente->juegoIds)->get();

        if (! $this->debeEjecutar($miembros, $fecha)) {
            FacadeLog::info("ScrapeSourceJob: {$this->sourceKey} omitida, todos los miembros programados tienen fila ({$fecha} {$this->horaObjetivo})");

            return;
        }

        try {
            $scraper = $resolver->instantiateClass($fuente->scraperClass, $miembros->first());
            $resultados = $scraper->execute($fecha);

            if (empty($resultados)) {
                FacadeLog::warning("ScrapeSourceJob: {$this->sourceKey} sin resultados para {$fecha}");
                $this->logToDatabase('warning', 'Sin resultados', [
                    'fuente' => $this->sourceKey,
                    'fecha' => $fecha,
                ]);

                return;
            }

            $total = 0;
            $porJuego = [];

            foreach (collect($resultados)->groupBy('juego_id') as $juegoId => $grupo) {
                $guardados = $scraper->saveResults($grupo->all(), $fecha);
                $porJuego[(int) $juegoId] = $guardados;
                $total += $guardados;
            }

            FacadeLog::info("ScrapeSourceJob: {$this->sourceKey} guardó {$total} filas ({$fecha})");

            // Resultados del día por juego: alimentan la evidencia de ingesta y
            // la liquidación sin consultar dos veces la base por juego.
            $resultadosPorJuego = [];
            $resultadoIds = [];
            foreach ($miembros as $juego) {
                $delJuego = Resultado::with('juego')
                    ->where('juego_id', $juego->id)
                    ->whereDate('fecha_sorteo', $fecha)
                    ->orderBy('id')
                    ->get();

                $resultadosPorJuego[$juego->id] = $delJuego;

                foreach ($delJuego as $resultado) {
                    $resultadoIds[] = (int) $resultado->id;
                }
            }

            $this->registrarIngesta($fecha, $total, $porJuego, $resultadoIds);

            $totalGanadoras = 0;
            $evaluados = 0;
            $apuestaService = app(ApuestaService::class);
            foreach ($resultadosPorJuego as $delJuego) {
                foreach ($delJuego as $resultado) {
                    $totalGanadoras += $apuestaService->verificarGanadores($resultado);
                    $evaluados++;
                }
            }

            $this->registrarSettlement($fecha, $totalGanadoras, $evaluados);

            FacadeLog::info("ScrapeSourceJob: {$this->sourceKey} jugadas ganadoras detectadas: {$totalGanadoras}");
            $this->logToDatabase('info', 'Scrape por fuente completado', [
                'fuente' => $this->sourceKey,
                'fecha' => $fecha,
                'hora_objetivo' => $this->horaObjetivo,
                'total' => $total,
                'por_juego' => $porJuego,
            ]);
        } catch (\Exception $e) {
            FacadeLog::error("ERROR ScrapeSourceJob {$this->sourceKey}: ".$e->getMessage());
            $this->logToDatabase('error', 'Error en scrape por fuente', [
                'fuente' => $this->sourceKey,
                'fecha' => $fecha,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function debeEjecutar(Collection $miembros, string $fecha): bool
    {
        if ($this->horaObjetivo === null) {
            return true;
        }

        $faltantes = $miembros->filter(function (Juego $juego) use ($fecha) {
            $programado = JuegoHorario::where('juego_id', $juego->id)
                ->where('active', true)
                ->get()
                ->contains(fn (JuegoHorario $horario) => substr($horario->hora, 0, 5) === $this->horaObjetivo);

            if (! $programado) {
                return false;
            }

            return ! Resultado::where('juego_id', $juego->id)
                ->whereDate('fecha_sorteo', $fecha)
                ->where('hora_sorteo', $this->horaObjetivo)
                ->exists();
        });

        return $faltantes->isNotEmpty();
    }

    protected function logToDatabase(string $level, string $message, array $context = []): void
    {
        try {
            Log::create([
                'user_id' => null,
                'action' => 'scrape_resultados',
                'details' => array_merge([
                    'level' => $level,
                    'message' => $message,
                ], $context),
                'ip' => 'system',
                'user_agent' => 'ScrapeSourceJob',
            ]);
        } catch (\Exception $e) {
            FacadeLog::error('Error al guardar log en DB: '.$e->getMessage());
        }
    }

    /**
     * P1 best-effort: la ingesta ya está persistida; un fallo de auditoría no
     * revierte ni bloquea el job. `entityId` es el primer resultado del lote
     * (representativo), porque el evento agrupa varios sorteos; los ids viajan
     * acotados en `after.resultados`.
     *
     * @param  array<int, int>  $porJuego
     * @param  array<int, int>  $resultadoIds
     */
    private function registrarIngesta(string $fecha, int $total, array $porJuego, array $resultadoIds): void
    {
        app(AuditRecorder::class)->record(
            AuditAction::DrawResultIngested,
            new AuditEventData(
                action: AuditAction::DrawResultIngested,
                entityType: 'resultado',
                entityId: $resultadoIds !== [] ? $resultadoIds[0] : null,
                after: [
                    'total' => $total,
                    'por_juego' => $porJuego,
                    'resultados' => array_slice($resultadoIds, 0, 50),
                ],
                context: array_filter([
                    'source' => 'job',
                    'source_key' => $this->sourceKey,
                    'fecha' => $fecha,
                    'hora_objetivo' => $this->horaObjetivo,
                    'total' => $total,
                    'por_juego' => $porJuego,
                ], static fn (mixed $value): bool => $value !== null),
            ),
        );
    }

    /**
     * P1 best-effort tras la detección de ganadores. Sin payload por apuesta:
     * solo el agregado del lote.
     */
    private function registrarSettlement(string $fecha, int $ganadoras, int $evaluados): void
    {
        app(AuditRecorder::class)->record(
            AuditAction::DrawSettlementCompleted,
            new AuditEventData(
                action: AuditAction::DrawSettlementCompleted,
                entityType: 'resultado',
                after: ['ganadoras' => $ganadoras],
                context: array_filter([
                    'source' => 'job',
                    'source_key' => $this->sourceKey,
                    'fecha' => $fecha,
                    'resultados_evaluados' => $evaluados,
                ], static fn (mixed $value): bool => $value !== null),
            ),
        );
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

    public function failed(\Throwable $exception): void
    {
        FacadeLog::error("ScrapeSourceJob (source_key={$this->sourceKey}) falló: ".$exception->getMessage());
    }
}
