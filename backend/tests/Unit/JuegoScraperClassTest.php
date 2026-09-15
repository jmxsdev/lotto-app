<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Plugins\Scrapers\TripletasScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JuegoScraperClassTest extends TestCase
{
    use RefreshDatabase;

    public function test_scraper_class_es_fillable_y_se_persiste(): void
    {
        $juego = Juego::create([
            'name' => 'Juego Prueba',
            'slug' => 'juego-prueba',
            'type' => 'tripletas',
            'scraper_class' => TripletasScraper::class,
            'requires_scraper' => true,
            'active' => true,
        ]);

        $this->assertEquals(TripletasScraper::class, $juego->scraper_class);
        $this->assertEquals(TripletasScraper::class, $juego->fresh()->scraper_class);
    }

    public function test_scraper_class_no_se_expone_en_payload_api(): void
    {
        $juego = Juego::create([
            'name' => 'Juego Prueba',
            'slug' => 'juego-prueba',
            'type' => 'tripletas',
            'scraper_class' => TripletasScraper::class,
            'requires_scraper' => true,
            'active' => true,
        ]);

        $payload = $juego->toArray();

        $this->assertArrayNotHasKey('scraper_class', $payload);
        $this->assertEquals(TripletasScraper::class, $juego->scraper_class);
    }

    public function test_scraper_class_nullable_sin_scraper_explicito(): void
    {
        $juego = Juego::create([
            'name' => 'Juego Sin Scraper',
            'slug' => 'juego-sin-scraper',
            'type' => 'animalitos',
            'requires_scraper' => false,
            'active' => true,
        ]);

        $this->assertNull($juego->scraper_class);
    }
}
