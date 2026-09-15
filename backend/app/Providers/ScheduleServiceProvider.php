<?php

namespace App\Providers;

use App\Jobs\ScrapeResultsJob;
use App\Models\Juego;
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

        $juegos = Juego::where('requires_scraper', true)->with('horarios')->get();

        foreach ($juegos as $juego) {
            foreach ($juego->horarios as $horario) {
                $horaLocal = substr($horario->hora, 0, 5);

                // Los horarios se registran EN HORA LOCAL (America/Caracas):
                // Laravel interpreta dailyAt() en la zona horaria de la app
                // (config/app.php), así que NO hay que convertir a UTC — hacerlo
                // correría los jobs 4 horas tarde (doble conversión).
                Schedule::job(new ScrapeResultsJob($juego->id))
                    ->dailyAt($horaLocal)
                    ->name("scrape_{$juego->slug}_{$horaLocal}")
                    ->withoutOverlapping(5);
            }

            Log::info("Schedule registrado para {$juego->name}: {$juego->horarios->count()} horarios");
        }
    }
}
