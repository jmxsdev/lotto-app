<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Providers\ScheduleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class ScheduleTimeZoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Los horarios de scrape deben registrarse EN HORA LOCAL (America/Caracas).
     *
     * Regresión: se convirtía Caracas->UTC y luego dailyAt() interpretaba la
     * hora en la zona de la app (Caracas) → los jobs corrían 4 horas tarde.
     */
    public function test_los_horarios_se_registran_en_hora_local_caracas(): void
    {
        $juego = Juego::create([
            'name' => 'Lotto Activo',
            'slug' => 'lotto-activo',
            'type' => 'animalitos',
            'config' => ['premio_multiplo' => 30],
            'requires_scraper' => true,
            'active' => true,
        ]);

        JuegoHorario::create([
            'juego_id' => $juego->id,
            'hora' => '08:00:00',
            'active' => true,
        ]);

        $provider = new ScheduleServiceProvider($this->app);
        $provider->boot();

        $evento = collect(Schedule::events())
            ->first(fn ($event) => $event->description === 'scrape_lotto-activo_08:00');

        $this->assertNotNull($evento, 'El horario 08:00 de lotto-activo debe estar registrado en la agenda.');
        $this->assertSame('0 8 * * *', $evento->expression, 'El job debe disparar a las 08:00 hora local (Caracas), no en UTC.');
    }

    public function test_los_horarios_no_se_desplazan_por_conversion_utc(): void
    {
        $juego = Juego::create([
            'name' => 'Triple Táchira',
            'slug' => 'triple-tachira',
            'type' => 'tripletas',
            'config' => ['premio_multiplo' => 500],
            'requires_scraper' => true,
            'active' => true,
        ]);

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
}
