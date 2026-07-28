<?php

namespace App\Jobs;

use App\Models\Juego;
use App\Models\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log as FacadeLog;
use Illuminate\Support\Str;

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

        if (!$juego) {
            FacadeLog::warning("ScrapeResultsJob: Juego ID {$this->juegoId} no encontrado");
            return;
        }

        if (!$juego->requires_scraper) {
            FacadeLog::info("ScrapeResultsJob: {$juego->name} no requiere scraper");
            return;
        }

        FacadeLog::info("=== INICIO ScrapeResultsJob para {$juego->name} fecha: {$fecha} ===");

        $scraperClass = $this->resolveScraper($juego->type);

        if (!$scraperClass || !class_exists($scraperClass)) {
            FacadeLog::warning("No existe scraper para type: {$juego->type}");
            $this->logToDatabase('warning', "No existe scraper", [
                'juego' => $juego->name,
                'type' => $juego->type,
            ]);
            return;
        }

        try {
            $scraper = new $scraperClass();
            $resultados = $scraper->execute($fecha);

            if (empty($resultados)) {
                FacadeLog::warning("{$juego->name}: sin resultados para fecha {$fecha}");

                if ($this->attempts() < $this->tries) {
                    $this->release($this->backoff);
                    return;
                }

                FacadeLog::warning("{$juego->name}: sin resultados tras {$this->tries} intentos");
                $this->logToDatabase('warning', "Sin resultados tras reintentos", [
                    'juego' => $juego->name,
                    'fecha' => $fecha,
                ]);
                return;
            }

            $guardados = $scraper->saveResults($resultados, $fecha);

            FacadeLog::info("{$juego->name}: {$guardados} resultados guardados");
            $this->logToDatabase('info', "Scrape completado", [
                'juego' => $juego->name,
                'fecha' => $fecha,
                'guardados' => $guardados,
            ]);

        } catch (\Exception $e) {
            FacadeLog::error("ERROR ScrapeResultsJob {$juego->name}: " . $e->getMessage());
            $this->logToDatabase('error', "Error en scrape", [
                'juego' => $juego->name,
                'fecha' => $fecha,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        FacadeLog::info("=== FIN ScrapeResultsJob {$juego->name} ===");
    }

    protected function resolveScraper(string $type): ?string
    {
        $class = 'App\\Plugins\\Scrapers\\' . Str::studly($type) . 'Scraper';
        return class_exists($class) ? $class : null;
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
            FacadeLog::error("Error al guardar log en DB: " . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        FacadeLog::error("ScrapeResultsJob (juego_id={$this->juegoId}) falló tras {$this->tries} intentos: " . $exception->getMessage());
    }
}
