<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\Resultado;
use App\Models\ScrapeState;
use App\Plugins\Scrapers\BaseScraper;
use App\Services\ApuestaService;
use App\Services\ScrapeRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ScrapeRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('scrapers.registrations', [
            'test-page' => [
                'default_multiplier' => 30,
                'retries' => 2,
                'games' => [
                    'juego-test' => ['class' => TestScraper::class, 'page_slug' => 'test'],
                ],
            ],
        ]);
        Config::set('scrapers.legacy_resolution', false);
        Config::set('scrapers.retries', 2);
        Config::set('scrapers.telegram.bot_token', null);
        Config::set('scrapers.telegram.chat_id', null);
    }

    protected function makeJuego(): Juego
    {
        return Juego::create([
            'name' => 'Juego Test',
            'slug' => 'juego-test',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'scraper_url' => 'https://example.com/resultados/test/',
            'active' => true,
        ]);
    }

    protected function runner(): ScrapeRunner
    {
        return app(ScrapeRunner::class);
    }

    public function test_successful_run_saves_results_and_state(): void
    {
        $juego = $this->makeJuego();
        TestScraper::$juegoId = $juego->id;
        TestScraper::$response = $this->buildPayload();

        $salida = $this->runner()->run($juego, '2026-08-13');

        $this->assertSame('ok', $salida['status']);
        $this->assertSame(2, $salida['guardados']);
        $this->assertSame(2, Resultado::count());

        $state = ScrapeState::where('juego_id', $juego->id)->where('fecha', '2026-08-13')->first();
        $this->assertSame('success', $state->estado);
        $this->assertSame(1, $state->intentos);
        $this->assertNull($state->ultimo_error);
    }

    public function test_unknown_game_records_failed_state(): void
    {
        $juego = Juego::create([
            'name' => 'Sin Registro',
            'slug' => 'sin-registro',
            'type' => 'animalitos',
            'requires_scraper' => true,
            'active' => true,
        ]);

        $salida = $this->runner()->run($juego, '2026-08-13');

        $this->assertSame('error', $salida['status']);
        $state = ScrapeState::where('juego_id', $juego->id)->where('fecha', '2026-08-13')->first();
        $this->assertSame('failed', $state->estado);
        $this->assertStringContainsString('no registrado', $state->ultimo_error);
    }

    public function test_failure_reaches_dead_letter_after_retries(): void
    {
        $juego = $this->makeJuego();
        TestScraper::$throw = true;
        TestScraper::$juegoId = $juego->id;

        // Intento 1: failed (intentos 1 < retries 2)
        try {
            $this->runner()->run($juego, '2026-08-13');
        } catch (\RuntimeException $e) {
            // esperado
        }

        // Intento 2 (retry del job): intentos 2 >= retries 2 → dead_letter
        try {
            $this->runner()->run($juego, '2026-08-13');
        } catch (\RuntimeException $e) {
            // esperado
        }

        $state = ScrapeState::where('juego_id', $juego->id)->where('fecha', '2026-08-13')->first();
        $this->assertSame('dead_letter', $state->estado);
        $this->assertSame(2, $state->intentos);
        $this->assertStringContainsString('boom', $state->ultimo_error);
    }

    public function test_reprocess_resets_state_and_reruns(): void
    {
        $juego = $this->makeJuego();
        TestScraper::$throw = true;
        TestScraper::$juegoId = $juego->id;

        // Alcanzar dead_letter (2 intentos con retries=2)
        for ($i = 0; $i < 2; $i++) {
            try {
                $this->runner()->run($juego, '2026-08-13');
            } catch (\RuntimeException $e) {
                // esperado
            }
        }

        $state = ScrapeState::where('juego_id', $juego->id)->where('fecha', '2026-08-13')->first();
        $this->assertSame('dead_letter', $state->estado);

        TestScraper::$throw = false;
        TestScraper::$response = $this->buildPayload();

        $salida = $this->runner()->reprocess($juego, '2026-08-13');

        $this->assertSame('ok', $salida['status']);
        $state = ScrapeState::where('juego_id', $juego->id)->where('fecha', '2026-08-13')->first();
        $this->assertSame('success', $state->estado);
        $this->assertSame(1, $state->intentos);
    }

    public function test_winner_verification_only_for_saved_rows(): void
    {
        $juego = $this->makeJuego();

        // Resultado stale (hora distinta) que NO debe verificarse
        Resultado::create([
            'juego_id' => $juego->id,
            'fecha_sorteo' => '2026-08-13',
            'hora_sorteo' => '23:59',
            'numeros_ganadores' => ['numero' => 1],
            'premios_detalle' => null,
        ]);

        $mock = $this->mock(ApuestaService::class, function ($mock) {
            $mock->shouldReceive('verificarGanadores')->times(2)->andReturn(1);
        });

        TestScraper::$juegoId = $juego->id;
        TestScraper::$response = $this->buildPayload();

        $salida = $this->runner()->run($juego, '2026-08-13');

        $this->assertSame('ok', $salida['status']);
        $this->assertSame(2, $salida['ganadoras_detectadas']);
    }

    protected function buildPayload(): string
    {
        return json_encode([
            ['juego_id' => 0, 'fecha_sorteo' => '2026-08-13', 'hora_sorteo' => '08:00', 'numeros_ganadores' => ['numero' => 7], 'sorteo_id_externo' => 'x1', 'premios_detalle' => null],
            ['juego_id' => 0, 'fecha_sorteo' => '2026-08-13', 'hora_sorteo' => '11:00', 'numeros_ganadores' => ['numero' => 9], 'sorteo_id_externo' => 'x2', 'premios_detalle' => null],
        ]);
    }
}

class TestScraper extends BaseScraper
{
    public static bool $throw = false;
    public static string $response = '';
    public static ?int $juegoId = null;

    protected string $baseUrl = 'https://example.com';
    protected string $scraperName = 'TestScraper';

    protected function fetch(string $fecha): string
    {
        if (self::$throw) {
            throw new \RuntimeException('boom');
        }

        return self::$response;
    }

    public function parse(string $rawData): array
    {
        return json_decode($rawData, true);
    }

    public function execute(?string $fecha = null): array
    {
        $resultados = $this->parse($this->fetch($fecha ?? now()->format('Y-m-d')));

        foreach ($resultados as &$resultado) {
            $resultado['juego_id'] = self::$juegoId;
        }

        return $resultados;
    }
}
