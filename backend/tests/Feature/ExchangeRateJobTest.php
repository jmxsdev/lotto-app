<?php

namespace Tests\Feature;

use App\Jobs\ScrapeExchangeRateJob;
use App\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Doble de ScrapeExchangeRateJob que corta SOLO el fetch de red: sobreescribe
 * fetchHtml() y devuelve un fixture (o lanza) en lugar de hacer el GET al BCV.
 * Parseo, guarda de idempotencia, transacción y logging siguen siendo reales.
 */
class ScrapeExchangeRateJobFake extends ScrapeExchangeRateJob
{
    public function __construct(
        private readonly string $html = '',
        private readonly bool $lanzaExcepcion = false,
    ) {}

    protected function fetchHtml(): string
    {
        if ($this->lanzaExcepcion) {
            throw new \Exception('Error de red simulado');
        }

        return $this->html;
    }
}

class ExchangeRateJobTest extends TestCase
{
    use RefreshDatabase;

    private function crearTasaActiva(float $rate, ?string $referenceDate = null): ExchangeRate
    {
        return ExchangeRate::create([
            'rate' => $rate,
            'base_currency' => 'USD',
            'reference_date' => $referenceDate !== null ? Carbon::parse($referenceDate) : now(),
            'set_by' => null,
            'notes' => 'Tasa previa',
            'is_active' => true,
        ]);
    }

    public function test_tasa_igual_no_inserta_y_conserva_la_fila_activa(): void
    {
        $activa = $this->crearTasaActiva(36.50, '2026-09-25 10:00:00');
        $fake = new ScrapeExchangeRateJobFake(
            file_get_contents(base_path('tests/Fixtures/bcv_dolar.html'))
        );

        $fake->handle();

        $this->assertSame(1, ExchangeRate::count(), 'Tasa sin cambios: no debe crearse ninguna fila nueva.');
        $activa->refresh();
        $this->assertTrue($activa->is_active, 'La fila activa debe permanecer activa.');
        $this->assertSame(36.50, $activa->rate, 'La tasa activa no debe modificarse.');
        $this->assertSame(
            '2026-09-25 10:00:00',
            $activa->reference_date->format('Y-m-d H:i:s'),
            'reference_date no debe refrescarse (se omite el INSERT, no se actualiza en sitio).'
        );
    }

    public function test_tasa_distinta_inserta_y_desactiva_la_anterior(): void
    {
        $anterior = $this->crearTasaActiva(35.00);
        $fake = new ScrapeExchangeRateJobFake(
            file_get_contents(base_path('tests/Fixtures/bcv_dolar.html'))
        );

        $fake->handle();

        $this->assertSame(2, ExchangeRate::count(), 'Tasa distinta: debe insertarse una fila nueva.');
        $anterior->refresh();
        $this->assertFalse($anterior->is_active, 'La tasa anterior debe quedar desactivada.');
        $this->assertDatabaseHas('exchange_rates', [
            'rate' => 36.50,
            'is_active' => true,
        ]);
    }

    public function test_fetch_fallido_conserva_estado_y_loguea_el_error(): void
    {
        $activa = $this->crearTasaActiva(36.50);
        Log::spy();
        $fake = new ScrapeExchangeRateJobFake(lanzaExcepcion: true);

        $fake->handle();

        $activa->refresh();
        $this->assertTrue($activa->is_active, 'Fallo de red: la tasa activa debe permanecer.');
        $this->assertSame(1, ExchangeRate::count(), 'Fallo de red: no debe crearse ninguna fila.');
        Log::shouldHaveReceived('error')
            ->with(\Mockery::pattern('/ERROR en ScrapeExchangeRateJob/'))
            ->once();
    }

    public function test_fixture_sin_selectores_conserva_estado_y_loguea_el_aviso(): void
    {
        $activa = $this->crearTasaActiva(36.50);
        Log::spy();
        $fake = new ScrapeExchangeRateJobFake(
            file_get_contents(base_path('tests/Fixtures/bcv_dolar_sin_tasa.html'))
        );

        $fake->handle();

        $activa->refresh();
        $this->assertTrue($activa->is_active, 'Página sin selectores: la tasa activa debe permanecer.');
        $this->assertSame(1, ExchangeRate::count(), 'Página sin selectores: no debe crearse ninguna fila.');
        Log::shouldHaveReceived('error')
            ->with('No se encontró la tasa en la página.')
            ->once();
    }
}
