<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeSourceJob;
use App\Services\DrawReconciliationService;
use App\Services\ScraperSourceResolver;
use Illuminate\Console\Command;

/**
 * Sweep de reconciliación de sorteos esperados-faltantes.
 *
 * Consulta `juego_horarios` (sorteos esperados con hora <= now - gracia) contra
 * `resultados` del día y despacha un `ScrapeSourceJob` SOLO para las fuentes con
 * faltantes (nunca todos los juegos). Día sano = 0 despachos (solo lecturas).
 *
 * - `--grace=50`: la fuente publica hasta ~45 min tarde (pasada +45); esperamos
 *   la gracia antes de considerar un sorteo faltante.
 * - `--window=180`: solo se rescatan huecos recientes; huecos más viejos son
 *   gaps permanentes de la fuente y los visibiliza la alerta, no el sweep.
 * - `--day-close` (cierre 23:45): gracia 0 y ventana de día completo; cubre los
 *   huecos de cadencia dispersa (2-4 h) al cierre.
 * - `--max-sources=20`: cap anti job-storm por corrida.
 * - `--dry-run`: lista faltantes sin despachar.
 * - `--force`: permite despachar fuera del entorno de producción.
 */
class ReconciliarSorteos extends Command
{
    /** Ventana de día completo en minutos (24 h) para `--day-close`. */
    private const VENTANA_DIA_COMPLETO = 1440;

    protected $signature = 'resultados:reconciliar
        {--grace=50 : Minutos de gracia tras la hora programada antes de considerar un sorteo faltante}
        {--window=180 : Minutos de ventana de recuperación (tras la gracia) para huecos recientes}
        {--max-sources=20 : Máximo de fuentes a despachar por corrida (cap anti job-storm)}
        {--day-close : Cierre de día: gracia 0 y ventana de día completo (registrado a las 23:45)}
        {--dry-run : Lista las fuentes con faltantes sin despachar}
        {--force : Permite despachar fuera del entorno de producción}';

    protected $description = 'Sweep de reconciliación: despacha ScrapeSourceJob solo para fuentes con sorteos esperados-faltantes (juego_horarios vs resultados)';

    public function handle(): int
    {
        $fecha = now()->format('Y-m-d');
        $dayClose = (bool) $this->option('day-close');
        $gracia = $dayClose ? 0 : (int) $this->option('grace');
        $ventana = $dayClose ? self::VENTANA_DIA_COMPLETO : (int) $this->option('window');
        $maxSources = (int) $this->option('max-sources');

        $faltantes = app(DrawReconciliationService::class)->missingByJuego($fecha, $gracia, $ventana);

        $resolver = app(ScraperSourceResolver::class);
        $porFuente = [];

        foreach ($faltantes as $entrada) {
            $fuente = $resolver->sourceOf($entrada['juego']);

            if ($fuente !== null) {
                $porFuente[$fuente->key] = $fuente;
            }
        }

        $porFuente = array_slice($porFuente, 0, $maxSources, true);

        $this->info(sprintf(
            'Reconciliación %s: %d fuente(s) con sorteos faltantes (gracia %d min, ventana %d min, max %d fuentes).',
            $fecha,
            count($porFuente),
            $gracia,
            $ventana,
            $maxSources
        ));

        foreach ($porFuente as $fuente) {
            $this->line('  - '.$fuente->key);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry-run: no se despachan jobs.');

            return self::SUCCESS;
        }

        if (! app()->environment('production') && ! $this->option('force')) {
            $this->warn('Entorno no producción: no se despacha sin --force.');

            return self::SUCCESS;
        }

        foreach ($porFuente as $fuente) {
            ScrapeSourceJob::dispatch($fuente->key, $fecha, null);
        }

        $this->info(sprintf('Despachados %d ScrapeSourceJob.', count($porFuente)));

        return self::SUCCESS;
    }
}
