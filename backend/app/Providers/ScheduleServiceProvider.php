<?php

namespace App\Providers;

use App\Jobs\ScrapeExchangeRateJob;
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
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Ventana de scrape de la tasa BCV: cada hora de 06:00 a 21:00 INCLUSIVE
        // (16 corridas/día) EN HORA LOCAL (America/Caracas). dailyAt() interpreta
        // la zona horaria de la app, así que NO se convierte a UTC (regla H21:
        // convertir correría el job 4 horas tarde). El mutex deriva del NOMBRE
        // (CallbackEvent), por eso cada hora tiene su propio candado.
        // Registrada ANTES del guard de la tabla `juegos`: la ventana no depende
        // de esa tabla y así `schedule:list` la muestra aunque la BD esté vacía
        // o el API aún esté migrando (el contenedor scheduler no migra).
        foreach (range(6, 21) as $hora) {
            Schedule::job(new ScrapeExchangeRateJob)
                ->dailyAt(sprintf('%02d:00', $hora))
                ->name(sprintf('scrape_tasa_bcv_%02d:00', $hora))
                ->withoutOverlapping(5);
        }

        if (! Schema::hasTable('juegos')) {
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

        // Sweep de reconciliación: cada 15 min rescata solo fuentes con sorteos
        // esperados-faltantes (juego_horarios vs resultados). Cierre de día 23:45:
        // gracia 0 + ventana de día completo cubre huecos de cadencia dispersa.
        Schedule::command('resultados:reconciliar')
            ->everyFifteenMinutes()
            ->name('reconciliar_resultados')
            ->withoutOverlapping(10);

        Schedule::command('resultados:reconciliar --day-close')
            ->dailyAt('23:45')
            ->name('reconciliar_resultados_cierre')
            ->withoutOverlapping(30);

        // Métricas de alerta: textfile Prometheus (resultados.prom) cada 15 min.
        Schedule::command('resultados:metricas')
            ->everyFifteenMinutes()
            ->name('resultados_metricas')
            ->withoutOverlapping(5);
    }
}
