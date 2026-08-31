<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Models\Resultado;
use App\Plugins\Scrapers\TripletasScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaseScraperHelpersTest extends TestCase
{
    use RefreshDatabase;

    protected TripletasScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scraper = new TripletasScraper;
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    public function test_normalize_hora_convierte_formato_12h_a_24h(): void
    {
        $this->assertEquals('10:00', $this->invocar('normalizeHora', '10:00 AM'));
        $this->assertEquals('20:30', $this->invocar('normalizeHora', '08:30 PM'));
        $this->assertEquals('12:00', $this->invocar('normalizeHora', '12:00 PM'));
        $this->assertEquals('00:00', $this->invocar('normalizeHora', '12:00 AM'));
    }

    public function test_normalize_hora_trunca_segundos(): void
    {
        $this->assertEquals('14:05', $this->invocar('normalizeHora', '14:05:33'));
        $this->assertEquals('08:05', $this->invocar('normalizeHora', '08:05'));
    }

    public function test_normalize_hora_devuelve_null_si_vacio_o_invalido(): void
    {
        $this->assertNull($this->invocar('normalizeHora', null));
        $this->assertNull($this->invocar('normalizeHora', ''));
        $this->assertNull($this->invocar('normalizeHora', 'no es una hora'));
    }

    public function test_find_juego_or_fail_resuelve_por_slug(): void
    {
        $juego = Juego::create([
            'name' => 'Triple Zulia',
            'slug' => 'triple-zulia',
            'type' => 'tripletas',
            'requires_scraper' => true,
            'active' => true,
        ]);

        $encontrado = $this->invocar('findJuegoOrFail', ['slug' => 'triple-zulia']);

        $this->assertEquals($juego->id, $encontrado->id);
    }

    public function test_find_juego_or_fail_resuelve_por_name(): void
    {
        $juego = Juego::create([
            'name' => 'Triple Zulia',
            'slug' => 'triple-zulia',
            'type' => 'tripletas',
            'requires_scraper' => true,
            'active' => true,
        ]);

        $encontrado = $this->invocar('findJuegoOrFail', ['name' => 'Triple Zulia']);

        $this->assertEquals($juego->id, $encontrado->id);
    }

    public function test_find_juego_or_fail_lanza_y_no_crea_filas(): void
    {
        $this->assertEquals(0, Juego::count());

        try {
            $this->invocar('findJuegoOrFail', ['slug' => 'juego-no-registrado']);
            $this->fail('Debería lanzar RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no registrado', $e->getMessage());
        }

        $this->assertEquals(0, Juego::count(), 'No debe crearse ningún juego en caliente');
    }

    public function test_save_results_upserta_y_no_duplica(): void
    {
        $juego = Juego::create([
            'name' => 'Triple Zulia',
            'slug' => 'triple-zulia',
            'type' => 'tripletas',
            'requires_scraper' => true,
            'active' => true,
        ]);

        $resultado = [
            'juego_id' => $juego->id,
            'fecha_sorteo' => '2026-07-25',
            'hora_sorteo' => '12:45',
            'numeros_ganadores' => ['triple_a' => '111', 'triple_b' => '222', 'triple_c' => '333', 'signo' => 'ARI', 'pais' => 'VE'],
            'sorteo_id_externo' => 'abc',
            'premios_detalle' => null,
        ];

        $this->scraper->saveResults([$resultado], '2026-07-25');
        $this->assertEquals(1, Resultado::count());

        $this->scraper->saveResults([$resultado], '2026-07-25');
        $this->assertEquals(1, Resultado::count(), 'No debe duplicarse el resultado');
    }
}