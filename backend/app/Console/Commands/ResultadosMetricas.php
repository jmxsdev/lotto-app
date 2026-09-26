<?php

namespace App\Console\Commands;

use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\Resultado;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Textfile de Prometheus con métricas de sorteos esperados/persistidos.
 *
 * Escribe `resultados.prom` (formato textfile de node-exporter) en el
 * directorio montado en el scheduler (volumen `/home/deploy/monitoring/textfile`
 * → `/var/lib/lotto-metrics:rw`). Prometheus lo scrapea vía el collector
 * textfile; las reglas de `alerts.yml` (MissingDraw, DailyDrawsIncomplete,
 * DrawMetricsStale) alertan por Telegram siguiendo la convención BackupNotRun.
 *
 * Métricas por juego (label `juego`=slug), para juegos activos con scraper:
 * - `lotto_draws_expected_today`: sorteos programados hoy (`juego_horarios`).
 * - `lotto_draws_persisted_today`: filas en `resultados` de hoy.
 * - `lotto_draws_missing`: vencidos (hora <= ahora) sin fila persistida.
 * - `lotto_draws_pending_seconds`: segundos desde el faltante más temprano.
 * - `lotto_daily_incomplete`: 1 si el conteo del día de referencia quedó por
 *   debajo del esperado. Referencia: AYER entre 00:00 y 23:44 (un gap del día
 *   anterior sigue visible como alerta); HOY a partir de las 23:45 (el día ya
 *   cerró con la pasada +45 del último sorteo).
 * - `lotto_metrics_timestamp`: época (unix) de esta escritura (sin label).
 *
 * La escritura es atómica (tmp + rename) y falla si el directorio no existe
 * (no se crea: el volumen es responsabilidad del compose).
 */
class ResultadosMetricas extends Command
{
    /** Nombre del textfile que scrapea node-exporter. */
    private const NOMBRE_ARCHIVO = 'resultados.prom';

    /** Umbral de referencia del conteo diario: de 23:45 en adelante es HOY. */
    private const HORA_CIERRE_REFERENCIA = '23:45';

    protected $signature = 'resultados:metricas
        {--path= : Directorio del textfile de node-exporter (default: /var/lib/lotto-metrics)}';

    protected $description = 'Exporta métricas de sorteos esperados/persistidos al textfile de Prometheus (resultados.prom)';

    public function handle(): int
    {
        $directorio = $this->option('path') ?: '/var/lib/lotto-metrics';

        if (! is_dir($directorio)) {
            $this->error("El directorio textfile no existe: {$directorio}");

            return self::FAILURE;
        }

        $contenido = $this->generarContenido();

        $archivoTmp = $directorio.'/'.self::NOMBRE_ARCHIVO.'.tmp';
        $archivoFinal = $directorio.'/'.self::NOMBRE_ARCHIVO;

        // Escritura atómica: primero el temporal en el mismo directorio (mismo
        // filesystem) y luego rename, para que node-exporter nunca lea a medias.
        if (File::put($archivoTmp, $contenido) === false) {
            $this->error("No se pudo escribir el textfile temporal: {$archivoTmp}");

            return self::FAILURE;
        }

        if (! rename($archivoTmp, $archivoFinal)) {
            @unlink($archivoTmp);
            $this->error("No se pudo mover el textfile a su destino: {$archivoFinal}");

            return self::FAILURE;
        }

        $this->info("Métricas de resultados escritas en {$archivoFinal}");

        return self::SUCCESS;
    }

    /**
     * Contenido del textfile en formato Prometheus (HELP/TYPE + muestras).
     */
    private function generarContenido(): string
    {
        $ahora = now();
        $fecha = $ahora->format('Y-m-d');
        $fechaAyer = $ahora->copy()->subDay()->format('Y-m-d');
        $fechaReferencia = $ahora->format('H:i') >= self::HORA_CIERRE_REFERENCIA
            ? $fecha
            : $fechaAyer;

        $horarios = JuegoHorario::query()
            ->where('active', true)
            ->whereHas('juego', function ($query) {
                $query->where('requires_scraper', true)->where('active', true);
            })
            ->get();

        $juegos = Juego::whereIn('id', $horarios->pluck('juego_id')->unique())
            ->get()
            ->keyBy('id');

        $persistidosHoy = $this->persistidosPorJuego($fecha);
        $persistidosReferencia = $fechaReferencia === $fecha
            ? $persistidosHoy
            : $this->persistidosPorJuego($fechaReferencia);

        $lineas = [];

        foreach ($juegos as $juego) {
            $horas = $horarios
                ->where('juego_id', $juego->id)
                ->pluck('hora')
                ->map(fn (string $hora) => substr($hora, 0, 5))
                ->values();

            $esperadosHoy = $horas->count();
            $persistidosDeHoy = $persistidosHoy->get($juego->id) ?? collect();
            $clavesPersistidas = $persistidosDeHoy
                ->map(fn (Resultado $resultado) => $resultado->hora_sorteo)
                ->flip();

            $vencidos = $horas->filter(fn (string $hora) => Carbon::parse($fecha.' '.$hora)->lte($ahora));
            $faltantes = $vencidos
                ->reject(fn (string $hora) => $clavesPersistidas->has($hora))
                ->sort()
                ->values();

            $missing = $faltantes->count();
            $pendienteSegundos = $faltantes->isEmpty()
                ? 0
                : max(0, $ahora->getTimestamp() - Carbon::parse($fecha.' '.$faltantes->first())->getTimestamp());

            $persistidosDeReferencia = $persistidosReferencia->get($juego->id) ?? collect();
            $incompleto = $persistidosDeReferencia->count() < $esperadosHoy ? 1 : 0;

            $lineas[] = "lotto_draws_expected_today{juego=\"{$juego->slug}\"} {$esperadosHoy}";
            $lineas[] = "lotto_draws_persisted_today{juego=\"{$juego->slug}\"} {$persistidosDeHoy->count()}";
            $lineas[] = "lotto_draws_missing{juego=\"{$juego->slug}\"} {$missing}";
            $lineas[] = "lotto_draws_pending_seconds{juego=\"{$juego->slug}\"} {$pendienteSegundos}";
            $lineas[] = "lotto_daily_incomplete{juego=\"{$juego->slug}\"} {$incompleto}";
        }

        $lineas[] = 'lotto_metrics_timestamp '.$ahora->getTimestamp();

        return $this->encabezados().implode("\n", $lineas)."\n";
    }

    /**
     * Resultados persistidos de una fecha agrupados por juego.
     *
     * @return Collection<int, Collection<int, Resultado>>
     */
    private function persistidosPorJuego(string $fecha): Collection
    {
        return Resultado::query()
            ->whereDate('fecha_sorteo', $fecha)
            ->get(['juego_id', 'hora_sorteo'])
            ->groupBy('juego_id');
    }

    /**
     * Bloques HELP/TYPE para que Prometheus documente cada métrica.
     */
    private function encabezados(): string
    {
        return <<<'PROM'
            # HELP lotto_draws_expected_today Sorteos esperados hoy por juego (juego_horarios activos)
            # TYPE lotto_draws_expected_today gauge
            # HELP lotto_draws_persisted_today Sorteos persistidos hoy por juego (resultados)
            # TYPE lotto_draws_persisted_today gauge
            # HELP lotto_draws_missing Sorteos vencidos sin persistir hoy por juego
            # TYPE lotto_draws_missing gauge
            # HELP lotto_draws_pending_seconds Segundos desde el sorteo faltante más temprano por juego
            # TYPE lotto_draws_pending_seconds gauge
            # HELP lotto_daily_incomplete 1 si el conteo diario quedó por debajo del esperado (ayer hasta 23:45, hoy después)
            # TYPE lotto_daily_incomplete gauge
            # HELP lotto_metrics_timestamp Época (unix) de la última escritura de métricas de resultados
            # TYPE lotto_metrics_timestamp gauge

            PROM;
    }
}
