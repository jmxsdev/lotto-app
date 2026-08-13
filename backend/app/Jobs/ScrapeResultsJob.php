<?php

namespace App\Jobs;

use App\Models\Juego;
use App\Services\ScrapeRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScrapeResultsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries;
    public $backoff;

    public function __construct(
        public int $juegoId,
        public ?string $fecha = null
    ) {
        $this->tries = (int) config('scrapers.retries', 3);
        $this->backoff = (int) config('scrapers.backoff', 300);
    }

    public function handle(): void
    {
        $juego = Juego::find($this->juegoId);

        if (! $juego) {
            Log::warning("ScrapeResultsJob: Juego ID {$this->juegoId} no encontrado");

            return;
        }

        if (! $juego->requires_scraper) {
            Log::info("ScrapeResultsJob: {$juego->name} no requiere scraper");

            return;
        }

        app(ScrapeRunner::class)->run($juego, $this->fecha);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ScrapeResultsJob (juego_id={$this->juegoId}) falló tras {$this->tries} intentos: ".$exception->getMessage());
    }
}
