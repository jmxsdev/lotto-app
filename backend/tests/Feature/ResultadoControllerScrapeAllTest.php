<?php

namespace Tests\Feature;

use App\Jobs\ScrapeSourceJob;
use App\Models\Juego;
use App\Models\User;
use App\Services\ScraperSourceResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ResultadoControllerScrapeAllTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function superUser(): User
    {
        $super = User::where('email', 'super@lotto.com')->first();
        $super->assignRole('super_master');

        return $super;
    }

    public function test_scrape_all_despacha_un_job_por_fuente_no_por_juego(): void
    {
        Bus::fake();

        // El endpoint opera sobre juegos ACTIVOS (`scrapeAll` filtra active).
        // El seed incluye juegos `requires_scraper` inactivos (La Ricachona,
        // REQ7): sus fuentes no entran en la consolidación despachada.
        $juegosActivos = Juego::where('requires_scraper', true)->where('active', true)->pluck('id')->all();
        $juegosConScraper = count($juegosActivos);
        $fuentes = collect(app(ScraperSourceResolver::class)->sources())
            ->filter(fn ($fuente) => array_intersect($fuente->juegoIds, $juegosActivos) !== [])
            ->values()
            ->all();

        $this->assertGreaterThan(
            count($fuentes),
            $juegosConScraper,
            'El seed debe tener más juegos que fuentes para que el test demuestre la consolidación.'
        );

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/resultados/scrape-all', ['fecha' => '2026-09-12']);

        $response->assertOk();
        $response->assertJsonStructure([
            'message',
            'fecha',
            'total_juegos',
            'resultados',
        ]);

        Bus::assertDispatched(ScrapeSourceJob::class, count($fuentes));

        $resultados = $response->json('resultados');
        $this->assertCount($juegosConScraper, $resultados, 'La respuesta sigue desglosada por juego (shape intacto).');
        foreach ($resultados as $entrada) {
            $this->assertArrayHasKey('status', $entrada);
            $this->assertArrayHasKey('ganadoras_detectadas', $entrada);
        }
        $this->assertSame('2026-09-12', $response->json('fecha'));
    }

    public function test_scrape_sin_juego_id_despacha_un_job_por_fuente(): void
    {
        Bus::fake();

        $fuentes = app(ScraperSourceResolver::class)->sources();

        $response = $this->actingAs($this->superUser(), 'sanctum')
            ->postJson('/api/v1/resultados/scrape', ['fecha' => '2026-09-12']);

        $response->assertOk();
        $response->assertJsonStructure([
            'message',
            'fecha',
            'resultados',
        ]);

        Bus::assertDispatched(ScrapeSourceJob::class, count($fuentes));
        $this->assertSame('2026-09-12', $response->json('fecha'));
    }

    public function test_scrape_all_sin_autenticacion_es_rechazado(): void
    {
        $response = $this->postJson('/api/v1/resultados/scrape-all', ['fecha' => '2026-09-12']);

        $response->assertUnauthorized();
    }
}
