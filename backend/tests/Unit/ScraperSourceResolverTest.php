<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\AnimalitosScraper;
use App\Plugins\Scrapers\LaGranjitaScraper;
use App\Plugins\Scrapers\TripleTachiraScraper;
use App\Plugins\Scrapers\TripletasScraper;
use App\Services\ScraperSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScraperSourceResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function crearJuego(array $datos): Juego
    {
        return Juego::create(array_merge([
            'name' => 'Juego '.uniqid(),
            'slug' => 'juego-'.uniqid(),
            'type' => 'animalitos',
            'requires_scraper' => true,
            'active' => true,
        ], $datos));
    }

    public function test_familia_lottoactivo_se_consolida_en_una_fuente(): void
    {
        $this->crearJuego([
            'slug' => 'lotto-activo',
            'name' => 'Lotto Activo',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
        ]);
        $this->crearJuego([
            'slug' => 'lotto-activo-rd',
            'name' => 'Lotto Activo RD Internacional',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
        ]);
        $this->crearJuego([
            'slug' => 'lotto-activo-rep-dom',
            'name' => 'Lotto Activo República Dominicana',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
        ]);
        $this->crearJuego([
            'slug' => 'monje-millonario',
            'name' => 'Monje Millonario',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
        ]);

        $fuentes = app(ScraperSourceResolver::class)->sources();

        $this->assertCount(1, $fuentes, 'Los 4 juegos del feed animalitos deben consolidarse en 1 fuente.');
        $fuente = $fuentes[0];
        $this->assertSame('lottoactivo-animalitos', $fuente->key);
        $this->assertSame(AnimalitosScraper::class, $fuente->scraperClass);
        $this->assertSame('animalitos', $fuente->slug);
        $this->assertCount(4, $fuente->juegoIds, 'La fuente debe contener los 4 juego_ids de la familia.');
    }

    public function test_trio_y_terminal_son_fuentes_distintas_entre_si(): void
    {
        $this->crearJuego([
            'slug' => 'trio-activo',
            'name' => 'Trío Activo',
            'type' => 'tripletas',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/trio_activo/',
        ]);
        $this->crearJuego([
            'slug' => 'terminal-activo',
            'name' => 'Terminal Activo',
            'type' => 'terminales',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/terminal_activo/',
        ]);

        $fuentes = app(ScraperSourceResolver::class)->sources();

        $this->assertCount(2, $fuentes);
        $claves = collect($fuentes)->pluck('key')->all();
        $this->assertContains('lottoactivo-trio_activo', $claves);
        $this->assertContains('lottoactivo-terminal_activo', $claves);
        $this->assertNotSame($fuentes[0]->key, $fuentes[1]->key, 'Trio y terminal comparten host pero tienen feeds distintos.');
    }

    public function test_resto_un_juego_por_fuente(): void
    {
        $this->crearJuego([
            'slug' => 'triple-zulia',
            'name' => 'Triple Zulia',
            'type' => 'tripletas',
            'scraper_url' => 'https://resultadostriplezulia.com/',
        ]);
        $this->crearJuego([
            'slug' => 'la-granjita',
            'name' => 'La Granjita',
            'scraper_url' => 'https://www.lagranjita.com/api/results.json?productId=1',
            'scraper_class' => LaGranjitaScraper::class,
        ]);
        $this->crearJuego([
            'slug' => 'triple-tachira',
            'name' => 'Triple Táchira',
            'type' => 'tripletas',
            'scraper_url' => 'https://tripletachira.com/pruebah.php',
            'scraper_class' => TripleTachiraScraper::class,
        ]);

        $fuentes = app(ScraperSourceResolver::class)->sources();

        $this->assertCount(3, $fuentes);
        foreach ($fuentes as $fuente) {
            $this->assertCount(1, $fuente->juegoIds, "La fuente {$fuente->key} debe tener 1 solo juego.");
        }
        $claves = collect($fuentes)->pluck('key')->sort()->values()->all();
        $this->assertSame(['la-granjita', 'triple-tachira', 'triple-zulia'], $claves);
    }

    public function test_source_of_devuelve_la_fuente_del_juego(): void
    {
        $lotto = $this->crearJuego([
            'slug' => 'lotto-activo',
            'name' => 'Lotto Activo',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
        ]);
        $zulia = $this->crearJuego([
            'slug' => 'triple-zulia',
            'name' => 'Triple Zulia',
            'type' => 'tripletas',
            'scraper_url' => 'https://resultadostriplezulia.com/',
        ]);

        $resolver = app(ScraperSourceResolver::class);

        $fuenteLotto = $resolver->sourceOf($lotto);
        $this->assertSame('lottoactivo-animalitos', $fuenteLotto->key);
        $this->assertContains($lotto->id, $fuenteLotto->juegoIds);

        $fuenteZulia = $resolver->sourceOf($zulia);
        $this->assertSame('triple-zulia', $fuenteZulia->key);
        $this->assertSame([$zulia->id], $fuenteZulia->juegoIds);
    }

    public function test_source_of_devuelve_null_si_el_juego_no_requiere_scraper(): void
    {
        $juego = Juego::create([
            'name' => 'Sin Scraper',
            'slug' => 'sin-scraper',
            'type' => 'animalitos',
            'requires_scraper' => false,
            'active' => true,
        ]);

        $this->assertNull(app(ScraperSourceResolver::class)->sourceOf($juego));
    }

    public function test_scraper_for_instancia_el_scraper_de_la_fuente(): void
    {
        $lotto = $this->crearJuego([
            'slug' => 'lotto-activo',
            'name' => 'Lotto Activo',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
        ]);
        $zulia = $this->crearJuego([
            'slug' => 'triple-zulia',
            'name' => 'Triple Zulia',
            'type' => 'tripletas',
            'scraper_url' => 'https://resultadostriplezulia.com/',
        ]);

        $resolver = app(ScraperSourceResolver::class);

        $this->assertInstanceOf(AnimalitosScraper::class, $resolver->scraperFor($lotto));
        $this->assertInstanceOf(TripletasScraper::class, $resolver->scraperFor($zulia));
    }

    public function test_scraper_for_devuelve_null_si_no_hay_scraper_resoluble(): void
    {
        $juego = $this->crearJuego([
            'slug' => 'tipo-sin-scraper',
            'type' => 'terminales',
            'scraper_url' => 'https://otrafuente.com/resultados/',
        ]);

        $this->assertNull(app(ScraperSourceResolver::class)->scraperFor($juego));
    }

    public function test_scraper_class_explicito_es_autoritativo_sobre_url(): void
    {
        $juego = $this->crearJuego([
            'type' => 'tripletas',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
            'scraper_class' => TripletasScraper::class,
        ]);

        $this->assertSame(TripletasScraper::class, app(ScraperSourceResolver::class)->scraperClassFor($juego));
    }

    public function test_scraper_class_inexistente_devuelve_null(): void
    {
        $juego = $this->crearJuego([
            'scraper_url' => 'https://otrafuente.com/resultados/',
            'scraper_class' => 'App\\Plugins\\Scrapers\\ScraperInexistente',
        ]);

        $this->assertNull(app(ScraperSourceResolver::class)->scraperClassFor($juego));
    }

    public function test_regresion_trio_activo_url_gana_sobre_convencion(): void
    {
        $juego = $this->crearJuego([
            'type' => 'tripletas',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/trio_activo/',
        ]);

        $this->assertSame(AnimalitosScraper::class, app(ScraperSourceResolver::class)->scraperClassFor($juego));
    }

    public function test_fallback_url_lottoactivo_resuelve_animalitos(): void
    {
        $juego = $this->crearJuego([
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
        ]);

        $this->assertSame(AnimalitosScraper::class, app(ScraperSourceResolver::class)->scraperClassFor($juego));
    }

    public function test_fallback_url_triplezulia_resuelve_tripletas(): void
    {
        $juego = $this->crearJuego([
            'type' => 'tripletas',
            'scraper_url' => 'https://resultadostriplezulia.com/',
        ]);

        $this->assertSame(TripletasScraper::class, app(ScraperSourceResolver::class)->scraperClassFor($juego));
    }

    public function test_fallback_convencion_por_type_resuelve_clase_existente(): void
    {
        $juego = $this->crearJuego([
            'type' => 'animalitos',
            'scraper_url' => 'https://otrafuente.com/resultados/',
        ]);

        $this->assertSame(AnimalitosScraper::class, app(ScraperSourceResolver::class)->scraperClassFor($juego));
    }

    public function test_fallback_convencion_devuelve_null_si_clase_no_existe(): void
    {
        $juego = $this->crearJuego([
            'type' => 'terminales',
            'scraper_url' => 'https://otrafuente.com/resultados/',
        ]);

        $this->assertNull(app(ScraperSourceResolver::class)->scraperClassFor($juego));
    }

    public function test_instantiate_animalitos_deriva_slug_de_la_url(): void
    {
        $juego = $this->crearJuego([
            'slug' => 'trio-activo',
            'name' => 'Trío Activo',
            'type' => 'tripletas',
            'scraper_url' => 'https://www.lottoactivo.com/resultados/trio_activo/',
        ]);

        $resolver = app(ScraperSourceResolver::class);
        $scraper = $resolver->instantiateClass(AnimalitosScraper::class, $juego);

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

    public function test_instantiate_pasa_el_juego_a_clases_no_legacy(): void
    {
        $juego = $this->crearJuego([
            'slug' => 'triple-zulia',
            'name' => 'Triple Zulia',
            'type' => 'tripletas',
            'scraper_url' => 'https://resultadostriplezulia.com/',
        ]);

        $resolver = app(ScraperSourceResolver::class);
        $scraper = $resolver->instantiateClass(TripletasScraper::class, $juego);

        $this->assertInstanceOf(TripletasScraper::class, $scraper);
    }
}
