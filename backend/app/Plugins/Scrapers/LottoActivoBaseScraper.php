<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use Illuminate\Support\Str;

/**
 * Shared lottoactivo.com page fetch, token extraction, and helpers.
 * One concrete subclass per game extracts only its own game's data.
 */
abstract class LottoActivoBaseScraper extends BaseScraper
{
    protected string $baseUrl = 'https://www.lottoactivo.com';

    /** Type assigned to created games of this scraper. */
    protected string $gameType = 'animalitos';

    /** Canonical slugs this scraper extracts (usually one). */
    protected array $acceptedSlugs = [];

    protected string $pageSlug;

    public function __construct(?string $pageSlug = null)
    {
        parent::__construct();
        $this->pageSlug = $pageSlug ?? 'animalitos';
        $this->scraperName = (new \ReflectionClass($this))->getShortName();
    }

    protected function fetch(string $fecha): string
    {
        $url = $this->baseUrl.'/resultados/'.$this->pageSlug.'/'.$fecha.'/';
        $html = $this->getHtml($url);

        $token = $this->extractToken($html);

        if (! $token) {
            throw new \RuntimeException('No se pudo extraer el token de la página');
        }

        $postData = [
            'option' => $token,
            'loteria' => $this->pageSlug,
            'fecha' => $fecha,
        ];

        return $this->client->post($this->baseUrl.'/core/process.php', [
            'form_params' => $postData,
            'headers' => [
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json',
                'Referer' => $url,
            ],
        ])->getBody()->getContents();
    }

    protected function extractToken(string $html): ?string
    {
        $crawler = $this->createCrawler($html);

        $scriptContent = $crawler->filter('script')->each(function ($node) {
            return $node->text();
        });

        if (in_array($this->pageSlug, ['trio_activo', 'terminal_activo'])) {
            foreach ($scriptContent as $script) {
                if (preg_match("/data\s*=\s*\{[^}]*'option'\s*:\s*'([^']+)'[^}]*'fecha'/s", $script, $matches)) {
                    return $matches[1];
                }
            }
        } else {
            foreach ($scriptContent as $script) {
                if (preg_match("/data\s*=\s*\{[^}]*'option'\s*:\s*'([^']+)'/", $script, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    protected function decodeJson(string $rawData): array
    {
        $data = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar JSON: '.json_last_error_msg());
        }

        return $data;
    }

    protected function canonicalSlug(string $rawSlug): string
    {
        return match ($rawSlug) {
            'lotto-activo-2-monje-millonario', 'lottoactivo2-monjemillonario' => 'monje-millonario',
            'terminal-trio' => 'terminal-activo',
            'lotto-activo-rd-internacional' => 'lotto-activo-rd',
            'lotto-activo-republica-dominicana' => 'lotto-activo-rep-dom',
            'lotto-activo' => 'lotto-activo',
            default => $rawSlug,
        };
    }

    /** Find or create the game for a nested-format item (has "name"). */
    protected function findOrCreateJuego(array $juegoData): ?Juego
    {
        $name = $juegoData['name'] ?? null;

        if (! $name) {
            return null;
        }

        $canonicalSlug = $this->canonicalSlug(Str::slug($name));

        if (! in_array($canonicalSlug, $this->acceptedSlugs, true)) {
            return null;
        }

        return Juego::firstOrCreate(
            ['slug' => $canonicalSlug],
            [
                'name' => $name,
                'type' => $this->gameType,
                'config' => ['premio_multiplo' => $this->defaultMultiplier()],
                'requires_scraper' => true,
                'scraper_url' => $this->baseUrl.'/resultados/'.$this->pageSlug.'/',
                'active' => true,
            ]
        );
    }

    /** Find or create the game for a flat-format scraper by exact name. */
    protected function findOrCreateJuegoByName(string $name, string $slug): ?Juego
    {
        return Juego::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'type' => $this->gameType,
                'config' => ['premio_multiplo' => $this->defaultMultiplier()],
                'requires_scraper' => true,
                'scraper_url' => $this->baseUrl.'/resultados/'.$this->pageSlug.'/',
                'active' => true,
            ]
        );
    }

    /** Nested-format mapping: animal-type games. */
    protected function mapAnimalitoResult(array $data, Juego $juego, array $juegoData): array
    {
        $pais = ($juegoData['pais'] ?? '1') === '1' ? 'Venezuela' : 'República Dominicana';

        return [
            'juego_id' => $juego->id,
            'fecha_sorteo' => now()->format('Y-m-d'),
            'hora_sorteo' => $data['time_s'] ?? null,
            'numeros_ganadores' => [
                'numero' => (int) ($data['number_animal'] ?? 0),
                'nombre_animal' => $data['name_animal'] ?? null,
                'imagen_animal' => $data['image_animal'] ?? null,
                'color_animal' => $data['color_animal'] ?? null,
                'pais' => $pais,
            ],
            'sorteo_id_externo' => $data['id_game'] ?? null,
            'premios_detalle' => null,
        ];
    }

    /** Flat-format mapping: trio (triple_a/b/c) or terminal (numero). */
    protected function mapFlatResult(array $data, Juego $juego): array
    {
        $numeros = [];

        if ($juego->type === 'tripletas') {
            $numeros['triple_a'] = $data['resultado1'] ?? null;
            $numeros['triple_b'] = $data['resultado2'] ?? null;
            $numeros['triple_c'] = $data['resultado3'] ?? null;
        } else {
            $numeros['numero'] = (int) ($data['resultado1'] ?? 0);
        }

        return [
            'juego_id' => $juego->id,
            'fecha_sorteo' => $data['fecha'] ?? now()->format('Y-m-d'),
            'hora_sorteo' => $data['time_s'] ?? null,
            'numeros_ganadores' => $numeros,
            'sorteo_id_externo' => $data['id'] ?? null,
            'premios_detalle' => null,
        ];
    }
}
