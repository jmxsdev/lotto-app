<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Plugins\Scrapers\BaseScraper;
use App\Plugins\Scrapers\ScraperEntry;
use App\Plugins\Scrapers\ScraperRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ScraperRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('scrapers.registrations', [
            'page-a' => [
                'default_multiplier' => 40,
                'games' => [
                    'juego-a' => ['class' => DummyScraperA::class, 'page_slug' => 'a'],
                    'duplicado' => ['class' => DummyScraperA::class, 'page_slug' => 'a'],
                ],
            ],
            'page-b' => [
                'default_multiplier' => 50,
                'games' => [
                    'duplicado' => ['class' => DummyScraperB::class, 'page_slug' => 'b'],
                ],
            ],
        ]);
        Config::set('scrapers.legacy_resolution', false);
    }

    protected function makeJuego(string $slug, ?string $url = null): Juego
    {
        return Juego::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => 'animalitos',
            'requires_scraper' => true,
            'scraper_url' => $url,
            'active' => true,
        ]);
    }

    public function test_resolves_known_game_through_registry(): void
    {
        $juego = $this->makeJuego('juego-a');

        $entry = ScraperRegistry::resolve($juego);

        $this->assertInstanceOf(ScraperEntry::class, $entry);
        $this->assertSame('page-a', $entry->key);
        $this->assertSame(DummyScraperA::class, $entry->class);
        $this->assertSame('a', $entry->pageSlug);
        $this->assertSame('juego-a', $entry->gameKey);
        $this->assertSame(40, $entry->defaultMultiplier);
    }

    public function test_unknown_game_resolves_to_null(): void
    {
        $juego = $this->makeJuego('no-registrado');

        $this->assertNull(ScraperRegistry::resolve($juego));
    }

    public function test_duplicate_registration_is_deterministic_first_wins(): void
    {
        $juego = $this->makeJuego('duplicado');

        $entry = ScraperRegistry::resolve($juego);

        $this->assertNotNull($entry);
        $this->assertSame('page-a', $entry->key);
        $this->assertSame(DummyScraperA::class, $entry->class);
    }

    public function test_legacy_fallback_resolves_when_toggle_enabled(): void
    {
        Config::set('scrapers.legacy_resolution', true);

        $juego = $this->makeJuego('fuera-de-registro', 'https://www.lottoactivo.com/resultados/animalitos/');

        $entry = ScraperRegistry::resolve($juego);

        $this->assertNotNull($entry);
        $this->assertSame('legacy', $entry->key);
    }

    public function test_legacy_fallback_disabled_returns_null(): void
    {
        $juego = $this->makeJuego('fuera-de-registro', 'https://www.lottoactivo.com/resultados/animalitos/');

        $this->assertNull(ScraperRegistry::resolve($juego));
    }

    public function test_make_instantiates_registered_class(): void
    {
        $juego = $this->makeJuego('juego-a');

        $entry = ScraperRegistry::resolve($juego);
        $scraper = ScraperRegistry::make($entry);

        $this->assertInstanceOf(DummyScraperA::class, $scraper);
    }
}

class DummyScraperA extends BaseScraper
{
    protected string $baseUrl = 'https://example.com';

    protected function fetch(string $fecha): string
    {
        return '';
    }

    protected function parse(string $rawData): array
    {
        return [];
    }
}

class DummyScraperB extends DummyScraperA
{
}
