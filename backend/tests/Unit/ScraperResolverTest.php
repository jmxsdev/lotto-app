<?php

namespace Tests\Unit;

use App\Jobs\ScrapeResultsJob;
use App\Models\Juego;
use App\Plugins\Scrapers\AnimalitosScraper;
use App\Plugins\Scrapers\TripletasScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScraperResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function resolverPara(array $datos): ?string
    {
        $juego = Juego::create(array_merge([
            'name' => 'Juego '.uniqid(),
            'slug' => 'juego-'.uniqid(),
            'type' => 'animalitos',
            'requires_scraper' => true,
            'active' => true,
        ], $datos));

        $job = new ScrapeResultsJob($juego->id, '2026-07-23');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('resolveScraper');
        $method->setAccessible(true);

        return $method->invoke($job, $juego);
    }

    public function test_scraper_class_explicito_es_autoritativo_sobre_url(): void
    {
        $clase = $this->resolverPara([
            'type' => 'tripletas',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
            'scraper_class' => TripletasScraper::class,
        ]);

        $this->assertEquals(TripletasScraper::class, $clase);
    }

    public function test_scraper_class_explicito_es_autoritativo_sobre_convencion(): void
    {
        $clase = $this->resolverPara([
            'type' => 'animalitos',
            'scraper_url' => 'https://otrafuente.com/resultados/',
            'scraper_class' => TripletasScraper::class,
        ]);

        $this->assertEquals(TripletasScraper::class, $clase);
    }

    public function test_scraper_class_inexistente_devuelve_null(): void
    {
        $clase = $this->resolverPara([
            'scraper_url' => 'https://otrafuente.com/resultados/',
            'scraper_class' => 'App\\Plugins\\Scrapers\\ScraperInexistente',
        ]);

        $this->assertNull($clase);
    }

    public function test_regresion_trio_activo_url_gana_sobre_convencion(): void
    {
        $clase = $this->resolverPara([
            'type' => 'tripletas',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/trio_activo/',
        ]);

        $this->assertEquals(AnimalitosScraper::class, $clase);
    }

    public function test_fallback_url_lottoactivo_resuelve_animalitos(): void
    {
        $clase = $this->resolverPara([
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
        ]);

        $this->assertEquals(AnimalitosScraper::class, $clase);
    }

    public function test_fallback_url_triplezulia_resuelve_tripletas(): void
    {
        $clase = $this->resolverPara([
            'type' => 'tripletas',
            'scraper_url' => 'https://resultadostriplezulia.com/',
        ]);

        $this->assertEquals(TripletasScraper::class, $clase);
    }

    public function test_fallback_convencion_por_type_resuelve_clase_existente(): void
    {
        $clase = $this->resolverPara([
            'type' => 'animalitos',
            'scraper_url' => 'https://otrafuente.com/resultados/',
        ]);

        $this->assertEquals(AnimalitosScraper::class, $clase);
    }

    public function test_fallback_convencion_devuelve_null_si_clase_no_existe(): void
    {
        $clase = $this->resolverPara([
            'type' => 'terminales',
            'scraper_url' => 'https://otrafuente.com/resultados/',
        ]);

        $this->assertNull($clase);
    }

    public function test_instantiate_scraper_animalitos_deriva_slug_de_la_url(): void
    {
        $juego = Juego::create([
            'name' => 'Trío Activo',
            'slug' => 'trio-activo',
            'type' => 'tripletas',
            'requires_scraper' => true,
            'scraper_url' => 'https://www.lottoactivo.com/resultados/trio_activo/',
            'active' => true,
        ]);

        $job = new ScrapeResultsJob($juego->id, '2026-07-23');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('instantiateScraper');
        $method->setAccessible(true);

        $scraper = $method->invoke($job, AnimalitosScraper::class, $juego);
        $this->assertInstanceOf(AnimalitosScraper::class, $scraper);

        $parse = new \ReflectionMethod($scraper, 'parse');
        $parse->setAccessible(true);

        $jsonPlano = json_encode(['datos' => [[
            'resultado1' => '486',
            'time_s' => '08:00 AM',
            'fecha' => '2026-07-23',
            'id' => '12345',
        ]]]);

        $resultados = $parse->invoke($scraper, $jsonPlano);

        $this->assertCount(1, $resultados);
        $this->assertEquals($juego->id, $resultados[0]['juego_id']);
        $this->assertEquals('486', $resultados[0]['numeros_ganadores']['triple_a']);
    }

    public function test_instantiate_scraper_pasa_el_juego_a_clases_no_legacy(): void
    {
        $juego = Juego::create([
            'name' => 'Triple Zulia',
            'slug' => 'triple-zulia',
            'type' => 'tripletas',
            'requires_scraper' => true,
            'scraper_url' => 'https://resultadostriplezulia.com/',
            'active' => true,
        ]);

        $job = new ScrapeResultsJob($juego->id, '2026-07-23');

        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('instantiateScraper');
        $method->setAccessible(true);

        $scraper = $method->invoke($job, TripletasScraper::class, $juego);

        $this->assertInstanceOf(TripletasScraper::class, $scraper);
    }
}