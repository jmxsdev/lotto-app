<?php

namespace App\Jobs;

use App\Models\Juego;
use App\Models\Log;
use App\Models\Resultado;
use App\Services\ApuestaService;
use App\Services\ScraperSourceResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log as FacadeLog;

class ScrapeResultsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 300;

    public function __construct(
        public int $juegoId,
        public ?string $fecha = null
    ) {}

    public function handle(): void
    {
        $fecha = $this->fecha ?? now()->format('Y-m-d');
        $juego = Juego::find($this->juegoId);

        if (! $juego) {
            FacadeLog::warning("ScrapeResultsJob: Juego ID {$this->juegoId} no encontrado");

            return;
        }

        if (! $juego->requires_scraper) {
            FacadeLog::info("ScrapeResultsJob: {$juego->name} no requiere scraper");

            return;
        }

        FacadeLog::info("=== INICIO ScrapeResultsJob para {$juego->name} fecha: {$fecha} ===");

        $scraper = app(ScraperSourceResolver::class)->scraperFor($juego);

        if (! $scraper) {
            FacadeLog::warning("No existe scraper para: {$juego->name} (type: {$juego->type}, url: {$juego->scraper_url})");
            $this->logToDatabase('warning', 'No existe scraper', [
                'juego' => $juego->name,
                'type' => $juego->type,
            ]);

            return;
        }

        try {
            $resultados = $scraper->execute($fecha);

            if (empty($resultados)) {
                FacadeLog::warning("{$juego->name}: sin resultados para fecha {$fecha}");

                if ($this->attempts() < $this->tries) {
                    $this->release($this->backoff);

                    return;
                }

                FacadeLog::warning("{$juego->name}: sin resultados tras {$this->tries} intentos");
                $this->logToDatabase('warning', 'Sin resultados tras reintentos', [
                    'juego' => $juego->name,
                    'fecha' => $fecha,
                ]);

                return;
            }

            $guardados = $scraper->saveResults($resultados, $fecha);

            FacadeLog::info("{$juego->name}: {$guardados} resultados guardados");

            $ultimosResultados = Resultado::with('juego')
                ->where('juego_id', $juego->id)
                ->whereDate('fecha_sorteo', $fecha)
                ->get();

            $apuestaService = app(ApuestaService::class);
            $totalGanadoras = 0;
            foreach ($ultimosResultados as $resultado) {
                $totalGanadoras += $apuestaService->verificarGanadores($resultado);
            }
            FacadeLog::info("{$juego->name}: jugadas ganadoras detectadas: {$totalGanadoras}");
            $this->logToDatabase('info', 'Scrape completado', [
                'juego' => $juego->name,
                'fecha' => $fecha,
                'guardados' => $guardados,
            ]);

        } catch (\Exception $e) {
            FacadeLog::error("ERROR ScrapeResultsJob {$juego->name}: ".$e->getMessage());
            $this->logToDatabase('error', 'Error en scrape', [
                'juego' => $juego->name,
                'fecha' => $fecha,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        FacadeLog::info("=== FIN ScrapeResultsJob {$juego->name} ===");
    }

    /**
     * Compatibilidad con tests previos: la resolución de clase vive ahora en
     * ScraperSourceResolver (contrato idéntico: scraper_class > URL > convención).
     */
    protected function resolveScraper(Juego $juego): ?string
    {
        return app(ScraperSourceResolver::class)->scraperClassFor($juego);
    }

    /**
     * Compatibilidad con tests previos: la instanciación (slug de lottoactivo
     * derivado de la URL, juego inyectado al resto de clases) vive en el resolver.
     */
    protected function instantiateScraper(string $class, Juego $juego): object
    {
        return app(ScraperSourceResolver::class)->instantiateClass($class, $juego);
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
                'user_agent' => 'ScrapeResultsJob',
            ]);
        } catch (\Exception $e) {
            FacadeLog::error('Error al guardar log en DB: '.$e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        FacadeLog::error("ScrapeResultsJob (juego_id={$this->juegoId}) falló tras {$this->tries} intentos: ".$exception->getMessage());
    }
}
