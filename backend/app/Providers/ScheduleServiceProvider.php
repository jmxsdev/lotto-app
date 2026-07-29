<?php

namespace App\Providers;

use App\Jobs\ScrapeResultsJob;
use App\Models\Juego;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class ScheduleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (!Schema::hasTable('juegos')) {
            return;
        }

        $juegos = Juego::where('requires_scraper', true)->with('horarios')->get();

        foreach ($juegos as $juego) {
            foreach ($juego->horarios as $horario) {
                $horaLocal = substr($horario->hora, 0, 5);

                $horaUtc = Carbon::createFromFormat('H:i', $horaLocal, 'America/Caracas')
                    ->setTimezone('UTC')
                    ->format('H:i');

                Schedule::job(new ScrapeResultsJob($juego->id))
                    ->dailyAt($horaUtc)
                    ->name("scrape_{$juego->slug}_{$horaLocal}")
                    ->withoutOverlapping(5);
            }

            Log::info("Schedule registrado para {$juego->name}: {$juego->horarios->count()} horarios");
        }
    }
}
