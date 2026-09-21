<?php

namespace App\Providers;

use App\Jobs\ScrapeSourceJob;
use App\Models\JuegoHorario;
use App\Services\ScraperSourceResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class ScheduleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! Schema::hasTable('juegos')) {
            return;
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $resolver = app(ScraperSourceResolver::class);

        foreach ($resolver->sources() as $fuente) {
            $horas = JuegoHorario::whereIn('juego_id', $fuente->juegoIds)
                ->where('active', true)
                ->pluck('hora')
                ->map(fn (string $hora) => substr($hora, 0, 5))
                ->unique()
                ->sort()
                ->values();

            foreach ($horas as $hora) {
                foreach ([0, 15, 30, 45] as $offset) {
                    $horaCorrida = Carbon::parse($hora)->addMinutes($offset)->format('H:i');
                    $sufijo = $offset === 0 ? '' : '+'.$offset;

                    // Los horarios se registran EN HORA LOCAL (America/Caracas):
                    // Laravel interpreta dailyAt() en la zona horaria de la app
                    // (config/app.php), así que NO hay que convertir a UTC — hacerlo
                    // correría los jobs 4 horas tarde (doble conversión, H21).
                    // Pasadas retardadas: hora, +15, +30, +45 con guarda en el job.
                    Schedule::job(new ScrapeSourceJob($fuente->key, null, $hora))
                        ->dailyAt($horaCorrida)
                        ->name("scrape_{$fuente->key}_{$hora}{$sufijo}")
                        ->withoutOverlapping(5);
                }
            }

            Log::info("Schedule registrado para la fuente {$fuente->key}: {$horas->count()} horas x 4 pasadas");
        }
    }
}
