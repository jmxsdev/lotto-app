<?php

namespace App\Services;

use App\Models\Juego;
use App\Models\Resultado;
use App\Models\ScrapeState;
use App\Notifications\ScrapeFailedNotification;
use App\Plugins\Scrapers\ScraperRegistry;
use Illuminate\Support\Facades\Notification;

/**
 * Single execution path for scrape work: resolve → execute → save →
 * record state → verify winners only for newly saved/updated rows.
 * Used by the queued job, API controller, admin controller, and reprocess.
 */
class ScrapeRunner
{
    public function __construct(
        private ApuestaService $apuestaService,
    ) {}

    /**
     * Run one scrape attempt for a game/date and record structured state.
     *
     * @return array{status: string, guardados?: int, ganadoras_detectadas?: int}
     *
     * @throws \Throwable when the scrape fails (caller decides retry policy)
     */
    public function run(Juego $juego, ?string $fecha = null): array
    {
        $fecha = $fecha ?? now()->format('Y-m-d');

        $entry = ScraperRegistry::resolve($juego);

        if (! $entry) {
            $this->recordState($juego, $fecha, 'failed', null, 'Scraper no registrado para este juego');

            return ['status' => 'error'];
        }

        $state = ScrapeState::firstOrCreate(['juego_id' => $juego->id, 'fecha' => $fecha]);
        $intentos = ($state->intentos ?? 0) + 1;
        $state->intentos = $intentos;

        try {
            $scraper = ScraperRegistry::make($entry);
            $resultados = $scraper->execute($fecha);

            if (empty($resultados)) {
                $state->estado = 'failed';
                $state->ultimo_error = 'Sin resultados para la fecha';
                $state->save();

                return ['status' => 'empty', 'guardados' => 0];
            }

            $guardados = $scraper->saveResults($resultados, $fecha);
            $ganadoras = $this->verifyWinnersForSavedRows($juego, $fecha, $resultados);

            $state->estado = 'success';
            $state->ultimo_error = null;
            $state->save();

            return ['status' => 'ok', 'guardados' => $guardados, 'ganadoras_detectadas' => $ganadoras];
        } catch (\Throwable $e) {
            $state->estado = 'failed';
            $state->ultimo_error = $e->getMessage();
            $state->save();

            if ($intentos >= ($entry->retries ?? (int) config('scrapers.retries', 3))) {
                $state->estado = 'dead_letter';
                $state->save();
                $this->notifyFailure($juego, $fecha, $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Manual reprocess of a terminally failed (dead-letter) scrape.
     * Resets the state to pending and runs once more.
     */
    public function reprocess(Juego $juego, ?string $fecha = null): array
    {
        $fecha = $fecha ?? now()->format('Y-m-d');

        $state = ScrapeState::firstOrCreate(['juego_id' => $juego->id, 'fecha' => $fecha]);
        $state->estado = 'pending';
        $state->ultimo_error = null;
        $state->intentos = 0;
        $state->save();

        return $this->run($juego, $fecha);
    }

    private function recordState(Juego $juego, string $fecha, string $estado, ?int $intentos, ?string $error): void
    {
        $state = ScrapeState::firstOrCreate(['juego_id' => $juego->id, 'fecha' => $fecha]);
        $state->estado = $estado;
        $state->ultimo_error = $error;

        if ($intentos !== null) {
            $state->intentos = $intentos;
        }

        $state->save();
    }

    /**
     * Winner verification only for rows saved/updated in THIS scrape:
     * the draw times present in the parsed results for the game/date.
     */
    private function verifyWinnersForSavedRows(Juego $juego, string $fecha, array $resultados): int
    {
        $horas = collect($resultados)
            ->pluck('hora_sorteo')
            ->filter()
            ->unique()
            ->all();

        $guardados = Resultado::with('juego')
            ->where('juego_id', $juego->id)
            ->whereDate('fecha_sorteo', $fecha)
            ->whereIn('hora_sorteo', $horas)
            ->get();

        $total = 0;
        foreach ($guardados as $resultado) {
            $total += $this->apuestaService->verificarGanadores($resultado);
        }

        return $total;
    }

    private function notifyFailure(Juego $juego, string $fecha, string $razon): void
    {
        $chatId = config('scrapers.telegram.chat_id');

        if (! $chatId) {
            return;
        }

        Notification::route('telegram', $chatId)
            ->notify(new ScrapeFailedNotification($juego->name, $fecha, $razon));
    }
}
