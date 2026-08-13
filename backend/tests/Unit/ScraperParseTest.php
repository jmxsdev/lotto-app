<?php

namespace Tests\Unit;

use App\Plugins\Scrapers\LottoActivoAnimalitosScraper;
use App\Plugins\Scrapers\LottoActivoMonjeMillonarioScraper;
use App\Plugins\Scrapers\LottoActivoRDScraper;
use App\Plugins\Scrapers\LottoActivoTerminalActivoScraper;
use App\Plugins\Scrapers\LottoActivoTrioActivoScraper;
use Tests\TestCase;

/**
 * Per-game scraper separation: each scraper extracts only its own game
 * from the shared page response (nested) or its own flat format.
 */
class ScraperParseTest extends TestCase
{
    protected function nestedFixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/animalitos_response.json'));
    }

    protected function parseWith(string $rawData, object $scraper, string $method = 'parse'): array
    {
        $reflection = new \ReflectionClass($scraper);
        $reflected = $reflection->getMethod($method);
        $reflected->setAccessible(true);

        return $reflected->invoke($scraper, $rawData);
    }

    public function test_animalitos_scraper_extracts_only_lotto_activo(): void
    {
        $scraper = new LottoActivoAnimalitosScraper();

        $resultados = $this->parseWith($this->nestedFixture(), $scraper);

        $this->assertCount(4, $resultados);
        $this->assertSame('Delfin', $resultados[0]['numeros_ganadores']['nombre_animal']);
        $this->assertSame('10:00 AM', $resultados[0]['hora_sorteo']);
        foreach ($resultados as $resultado) {
            $this->assertSame('Venezuela', $resultado['numeros_ganadores']['pais']);
        }
    }

    public function test_rd_scraper_extracts_only_lotto_activo_rd(): void
    {
        $scraper = new LottoActivoRDScraper();

        $resultados = $this->parseWith($this->nestedFixture(), $scraper);

        $this->assertCount(2, $resultados);
        foreach ($resultados as $resultado) {
            $this->assertSame('República Dominicana', $resultado['numeros_ganadores']['pais']);
        }
    }

    public function test_monje_scraper_extracts_nothing_from_animalitos_page(): void
    {
        $scraper = new LottoActivoMonjeMillonarioScraper();

        $resultados = $this->parseWith($this->nestedFixture(), $scraper);

        $this->assertEmpty($resultados);
    }

    public function test_trio_scraper_parses_flat_format(): void
    {
        $scraper = new LottoActivoTrioActivoScraper();

        $json = json_encode([
            'datos' => [
                ['resultado1' => '486', 'resultado2' => '123', 'resultado3' => '789', 'time_s' => '08:00 AM', 'fecha' => '2026-08-13', 'id' => '1001'],
                ['resultado1' => '111', 'resultado2' => '222', 'resultado3' => '333', 'time_s' => '08:30 PM', 'fecha' => '2026-08-13', 'id' => '1002'],
            ],
        ]);

        $resultados = $this->parseWith($json, $scraper);

        $this->assertCount(2, $resultados);
        $this->assertArrayHasKey('triple_a', $resultados[0]['numeros_ganadores']);
        $this->assertArrayHasKey('triple_b', $resultados[0]['numeros_ganadores']);
        $this->assertArrayHasKey('triple_c', $resultados[0]['numeros_ganadores']);
        $this->assertSame('486', $resultados[0]['numeros_ganadores']['triple_a']);
    }

    public function test_terminal_scraper_parses_flat_format(): void
    {
        $scraper = new LottoActivoTerminalActivoScraper();

        $json = json_encode([
            'datos' => [
                ['resultado1' => '42', 'time_s' => '08:00 AM', 'fecha' => '2026-08-13', 'id' => '2001'],
            ],
        ]);

        $resultados = $this->parseWith($json, $scraper);

        $this->assertCount(1, $resultados);
        $this->assertSame(42, $resultados[0]['numeros_ganadores']['numero']);
    }

    public function test_flat_scraper_ignores_non_flat_items(): void
    {
        $scraper = new LottoActivoTrioActivoScraper();

        $json = json_encode([
            'datos' => [
                ['name' => 'Lotto Activo', 'resultados' => []],
                ['resultado1' => '111', 'resultado2' => '222', 'resultado3' => '333', 'time_s' => '08:00 AM', 'fecha' => '2026-08-13'],
            ],
        ]);

        $resultados = $this->parseWith($json, $scraper);

        $this->assertCount(1, $resultados);
    }
}
