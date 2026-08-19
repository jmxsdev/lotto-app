<?php

namespace App\Jobs;

use App\Models\Log;
use App\Models\Resultado;
use App\Plugins\Scrapers\AnimalitosScraper;
use App\Services\ApuestaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log as FacadeLog;

class FetchResultsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 60;

    public function __construct(
        public ?string $fecha = null
    ) {}

    public function handle(): void
    {
        $fecha = $this->fecha ?? now()->format('Y-m-d');

        FacadeLog::info("=== INICIO FetchResultsJob para fecha: {$fecha} ===");

        try {
            $scraper = new AnimalitosScraper;
            $resultados = $scraper->execute($fecha);

            if (empty($resultados)) {
                FacadeLog::warning("No se obtuvieron resultados para fecha: {$fecha}");
                $this->logToDatabase('warning', 'No se obtuvieron resultados', ['fecha' => $fecha]);

                return;
            }

            $guardados = $scraper->saveResults($resultados, $fecha);

            FacadeLog::info("Resultados guardados: {$guardados}");

            $ultimosResultados = Resultado::with('juego')
                ->whereDate('fecha_sorteo', $fecha)
                ->get();

            $apuestaService = app(ApuestaService::class);
            $totalGanadoras = 0;
            foreach ($ultimosResultados as $resultado) {
                $totalGanadoras += $apuestaService->verificarGanadores($resultado);
            }
            FacadeLog::info("Jugadas ganadoras detectadas: {$totalGanadoras}");
            $this->logToDatabase('info', 'Scrape completado exitosamente', [
                'fecha' => $fecha,
                'resultados_guardados' => $guardados,
            ]);

        } catch (\Exception $e) {
            FacadeLog::error('ERROR en FetchResultsJob: '.$e->getMessage());
            FacadeLog::error('Trace: '.$e->getTraceAsString());

            $this->logToDatabase('error', 'Error en scrape: '.$e->getMessage(), [
                'fecha' => $fecha,
                'exception' => get_class($e),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }

        FacadeLog::info('=== FIN FetchResultsJob ===');
    }

    protected function logToDatabase(string $level, string $message, array $context = []): void
    {
        Log::create([
            'user_id' => null,
            'action' => 'scrape_animalitos',
            'details' => array_merge([
                'level' => $level,
                'message' => $message,
            ], $context),
            'ip' => 'system',
            'user_agent' => 'FetchResultsJob',
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        FacadeLog::error("FetchResultsJob falló después de {$this->tries} intentos: ".$exception->getMessage());

        $this->logToDatabase('error', 'Job falló después de reintentos', [
            'fecha' => $this->fecha,
            'error' => $exception->getMessage(),
        ]);
    }
}
