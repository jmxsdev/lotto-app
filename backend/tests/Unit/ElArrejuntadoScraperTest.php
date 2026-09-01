<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\ElArrejuntaoScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ElArrejuntadoScraperTest extends TestCase
{
    use RefreshDatabase;

    protected ElArrejuntaoScraper $scraper;

    protected function setUp(): void
    {
        parent::setUp();

        Juego::create([
            'name' => 'El Arrejuntado',
            'slug' => 'el-arrejuntado',
            'type' => 'tripletas',
            'requires_scraper' => true,
            'scraper_url' => 'https://backend.serviciosintegradostriple7.com/api/v1/products/el-arrejuntao/results/',
            'active' => true,
        ]);

        $this->scraper = new ElArrejuntaoScraper(
            Juego::where('slug', 'el-arrejuntado')->first()
        );
    }

    protected function invocar(string $metodo, ...$args): mixed
    {
        $reflection = new \ReflectionClass($this->scraper);
        $method = $reflection->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invoke($this->scraper, ...$args);
    }

    protected function parsearJson(string $json): mixed
    {
        return $this->invocar('parse', $json);
    }

    protected function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/elarrejuntao_results.json'));
    }

    public function test_parsea_los_draws_publicados_del_fixture(): void
    {
        $resultados = $this->parsearJson($this->fixture());

        $this->assertIsArray($resultados);
        $this->assertCount(1, $resultados);
    }

    public function test_ignora_los_draws_no_publicados(): void
    {
        $json = json_decode($this->fixture(), true);
        $json['draws'][0]['is_published'] = false;

        $resultados = $this->parsearJson(json_encode($json));

        $this->assertCount(0, $resultados);
    }

    public function test_normaliza_la_hora_12h_a_formato_24h(): void
    {
        $resultados = $this->parsearJson($this->fixture());

        $this->assertEquals(['10:00'], array_column($resultados, 'hora_sorteo'));
    }

    public function test_mapa_las_seis_modalidades_por_draw(): void
    {
        $resultados = $this->parsearJson($this->fixture());

        $numeros = $resultados[0]['numeros_ganadores'];

        // Modalidades no-tripletas conservadas en el mismo array
        $this->assertEquals('73', $numeros['animalito']);
        $this->assertEquals('1825', $numeros['arrimao']);
        $this->assertEquals('10503', $numeros['pegadito']);

        // Modalidades tripletas mapeadas al esquema del frontend
        $this->assertEquals('894', $numeros['triple_a']);
        $this->assertEquals('082', $numeros['triple_b']);
        // triple-signo "259 LEO" se divide en triple_c + signo
        $this->assertEquals('259', $numeros['triple_c']);
        $this->assertEquals('LEO', $numeros['signo']);

        $this->assertEquals('VE', $numeros['pais']);
    }

    public function test_estructura_de_resultado_completa(): void
    {
        $resultados = $this->parsearJson($this->fixture());

        $primero = $resultados[0];
        $this->assertArrayHasKey('juego_id', $primero);
        $this->assertArrayHasKey('hora_sorteo', $primero);
        $this->assertArrayHasKey('numeros_ganadores', $primero);
        $this->assertArrayHasKey('sorteo_id_externo', $primero);
        $this->assertArrayHasKey('premios_detalle', $primero);
        $this->assertNull($primero['premios_detalle']);

        // sorteo_id_externo usa el id único del draw
        $this->assertEquals('f4241e49-aa78-4589-bcfd-c4764914bb50', $primero['sorteo_id_externo']);

        $juego = Juego::where('slug', 'el-arrejuntado')->first();
        $this->assertEquals($juego->id, $primero['juego_id']);
    }

    public function test_fail_fast_sin_juego_registrado_no_crea_filas(): void
    {
        $scraper = new ElArrejuntaoScraper;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no registrado');

        $this->parsearJsonCon($scraper, $this->fixture());

        $this->fail('Debería lanzar RuntimeException');
    }

    protected function parsearJsonCon(ElArrejuntaoScraper $scraper, string $json): mixed
    {
        $reflection = new \ReflectionClass($scraper);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);

        return $method->invoke($scraper, $json);
    }

    public function test_ignora_draw_sin_modalidades_tripletas(): void
    {
        $json = json_decode($this->fixture(), true);
        // Un draw publicado pero sin resultados → no debe generar fila
        $json['draws'][0]['results'] = [];

        $resultados = $this->parsearJson(json_encode($json));

        $this->assertCount(0, $resultados);
    }
}
