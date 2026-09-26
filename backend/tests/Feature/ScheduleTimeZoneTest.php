<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Providers\ScheduleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScheduleTimeZoneTest extends TestCase
{
    use RefreshDatabase;

    protected function crearJuego(string $slug, string $url, string $type = 'animalitos'): Juego
    {
        $juego = Juego::create([
            'name' => $slug,
            'slug' => $slug,
            'type' => $type,
            'config' => ['premio_multiplo' => 30],
            'requires_scraper' => true,
            'scraper_url' => $url,
            'active' => true,
        ]);

        return $juego;
    }

    /**
     * Los horarios de scrape deben registrarse EN HORA LOCAL (America/Caracas).
     *
     * Regresión: se convertía Caracas->UTC y luego dailyAt() interpretaba la
     * hora en la zona de la app (Caracas) → los jobs corrían 4 horas tarde.
     */
    public function test_los_horarios_se_registran_en_hora_local_caracas(): void
    {
        $juego = $this->crearJuego('lotto-activo', 'https://www.lottoactivo.com/resultados/animalitos/');

        JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => '08:00:00',
            'active' => true,
        ]);

        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $evento = collect(Schedule::events())
            ->first(fn ($event) => $event->description === 'scrape_lottoactivo-animalitos_08:00');

        $this->assertNotNull($evento, 'El horario 08:00 de la fuente lottoactivo-animalitos debe estar registrado en la agenda.');
        $this->assertSame('0 8 * * *', $evento->expression, 'El job debe disparar a las 08:00 hora local (Caracas), no en UTC.');
    }

    public function test_los_horarios_no_se_desplazan_por_conversion_utc(): void
    {
        $juego = $this->crearJuego('triple-tachira', 'https://tripletachira.com/pruebah.php', 'tripletas');

        JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => '22:10:00',
            'active' => true,
        ]);

        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $evento = collect(Schedule::events())
            ->first(fn ($event) => $event->description === 'scrape_triple-tachira_22:10');

        $this->assertNotNull($evento);
        $this->assertSame('10 22 * * *', $evento->expression, 'El horario 22:10 debe quedar a las 22:10 local, sin el desplazamiento UTC.');
    }

    public function test_pasadas_retardadas_15_30_45_se_registran_por_fuente(): void
    {
        $juego = $this->crearJuego('lotto-activo', 'https://www.lottoactivo.com/resultados/animalitos/');

        JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => '08:00:00',
            'active' => true,
        ]);

        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $eventos = collect(Schedule::events())
            ->filter(fn ($event) => str_starts_with($event->description, 'scrape_lottoactivo-animalitos_08:00'));

        $this->assertCount(4, $eventos, 'Por cada hora de la fuente deben registrarse 4 pasadas: hora, +15, +30, +45.');

        $porNombre = $eventos->keyBy(fn ($event) => $event->description);

        $this->assertSame('0 8 * * *', $porNombre['scrape_lottoactivo-animalitos_08:00']->expression);
        $this->assertSame('15 8 * * *', $porNombre['scrape_lottoactivo-animalitos_08:00+15']->expression);
        $this->assertSame('30 8 * * *', $porNombre['scrape_lottoactivo-animalitos_08:00+30']->expression);
        $this->assertSame('45 8 * * *', $porNombre['scrape_lottoactivo-animalitos_08:00+45']->expression);
    }

    public function test_pasada_45_de_ultimo_sorteo_queda_dentro_del_dia(): void
    {
        $juego = $this->crearJuego('lotto-activo', 'https://www.lottoactivo.com/resultados/animalitos/');

        JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => '23:00:00',
            'active' => true,
        ]);

        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $evento = collect(Schedule::events())
            ->first(fn ($event) => $event->description === 'scrape_lottoactivo-animalitos_23:00+45');

        $this->assertNotNull($evento);
        $this->assertSame('45 23 * * *', $evento->expression, 'El último sorteo (23:00) debe tener su pasada +45 a las 23:45.');
    }

    public function test_la_familia_lottoactivo_se_registra_por_fuente_no_por_juego(): void
    {
        foreach (['lotto-activo', 'lotto-activo-rd', 'lotto-activo-rep-dom', 'monje-millonario'] as $slug) {
            $juego = $this->crearJuego($slug, 'https://www.lottoactivo.com/resultados/animalitos/');

            JuegoHorario::create([
                'juego_id' => $juego->id,
                'hora' => '08:00:00',
                'active' => true,
            ]);
        }

        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $eventos = collect(Schedule::events())
            ->filter(fn ($event) => str_starts_with($event->description, 'scrape_lottoactivo-animalitos_08:00'));

        $this->assertCount(4, $eventos, '4 juegos compartiendo el feed animalitos deben registrar 4 pasadas de fuente, no 16 (una por juego).');
    }

    public function test_el_sweep_de_reconciliacion_se_registra_cada_15_minutos(): void
    {
        $juego = $this->crearJuego('lotto-activo', 'https://www.lottoactivo.com/resultados/animalitos/');

        JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => '08:00:00',
            'active' => true,
        ]);

        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $evento = collect(Schedule::events())
            ->first(fn ($event) => $event->description === 'reconciliar_resultados');

        $this->assertNotNull($evento, 'El sweep de reconciliación debe estar registrado en la agenda.');
        $this->assertSame('*/15 * * * *', $evento->expression, 'El sweep debe correr cada 15 minutos.');
    }

    public function test_las_metricas_de_resultados_se_registran_cada_15_minutos(): void
    {
        $juego = $this->crearJuego('lotto-activo', 'https://www.lottoactivo.com/resultados/animalitos/');

        JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => '08:00:00',
            'active' => true,
        ]);

        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $evento = collect(Schedule::events())
            ->first(fn ($event) => $event->description === 'resultados_metricas');

        $this->assertNotNull($evento, 'El exportador de métricas debe estar registrado en la agenda.');
        $this->assertSame('*/15 * * * *', $evento->expression, 'resultados:metricas debe correr cada 15 minutos.');
    }

    public function test_el_cierre_de_dia_se_registra_a_las_23_45(): void
    {
        $juego = $this->crearJuego('lotto-activo', 'https://www.lottoactivo.com/resultados/animalitos/');

        JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => '23:00:00',
            'active' => true,
        ]);

        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $evento = collect(Schedule::events())
            ->first(fn ($event) => $event->description === 'reconciliar_resultados_cierre');

        $this->assertNotNull($evento, 'El cierre de día debe estar registrado en la agenda.');
        $this->assertSame('45 23 * * *', $evento->expression, 'El cierre de día debe correr a las 23:45.');
    }

    /**
     * La ventana del scrape de tasa BCV (06:00–21:00 inclusive, 16 corridas/día)
     * debe registrarse EN HORA LOCAL (America/Caracas). Assert por NOMBRE y no por
     * conteos: el provider se auto-registra al boot de la app y el test vuelve a
     * llamar boot(), así que cada nombre puede aparecer más de una vez.
     */
    public function test_la_ventana_de_tasa_bcv_se_registra_cada_hora_de_06_a_21(): void
    {
        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $porNombre = collect(Schedule::events())
            ->filter(fn ($event) => $event->description !== null && str_starts_with($event->description, 'scrape_tasa_bcv_'))
            ->keyBy(fn ($event) => $event->description);

        foreach (range(6, 21) as $hora) {
            $nombre = sprintf('scrape_tasa_bcv_%02d:00', $hora);
            $this->assertArrayHasKey($nombre, $porNombre, "Debe existir la entrada {$nombre}.");
            $this->assertSame(
                sprintf('0 %d * * *', $hora),
                $porNombre[$nombre]->expression,
                "La entrada {$nombre} debe disparar a las {$hora}:00 hora local (America/Caracas), sin conversión UTC."
            );
        }
    }

    public function test_la_ventana_de_tasa_bcv_reemplaza_la_cadencia_cada_seis_horas(): void
    {
        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $legacy = collect(Schedule::events())
            ->filter(fn ($event) => $event->expression === '0 */6 * * *');

        $this->assertSame(0, $legacy->count(), 'La cadencia everySixHours del job de tasa debe desaparecer de la agenda.');
    }

    public function test_la_ventana_de_tasa_se_registra_aunque_falte_la_tabla_juegos(): void
    {
        Schema::shouldReceive('hasTable')->andReturn(false);

        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $porNombre = collect(Schedule::events())
            ->filter(fn ($event) => $event->description !== null && str_starts_with($event->description, 'scrape_tasa_bcv_'))
            ->keyBy(fn ($event) => $event->description);

        foreach (range(6, 21) as $hora) {
            $nombre = sprintf('scrape_tasa_bcv_%02d:00', $hora);
            $this->assertArrayHasKey($nombre, $porNombre, 'Sin la tabla juegos la ventana debe seguir registrándose (bloque anterior al guard).');
        }
    }
}
