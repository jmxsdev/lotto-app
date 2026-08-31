<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;

class AnimalitosScraper extends BaseScraper
{
    protected string $baseUrl = 'https://www.lottoactivo.com';

    protected string $scraperName = 'AnimalitosScraper';

    protected string $slug;

    public function __construct(string $slug = 'animalitos')
    {
        parent::__construct();
        $this->slug = $slug;
        $this->scraperName = \Str::studly($slug).'Scraper';
    }

    public function fetch(string $fecha): string
    {
        $url = $this->baseUrl.'/resultados/'.$this->slug.'/'.$fecha.'/';
        $html = $this->getHtml($url);

        $token = $this->extractToken($html);

        if (! $token) {
            throw new \RuntimeException('No se pudo extraer el token de la página');
        }

        $postData = [
            'option' => $token,
            'loteria' => $this->slug,
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

    public function parse(string $rawData): array
    {
        $data = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar JSON: '.json_last_error_msg());
        }

        if (! isset($data['datos']) || ! is_array($data['datos'])) {
            $this->logWarning('Respuesta sin campo "datos" o vacío');

            return [];
        }

        $resultados = [];
        $primerItem = $data['datos'][0] ?? null;
        $esFormatoPlano = $primerItem && ! isset($primerItem['name']) && ! isset($primerItem['resultados']);

        // Formato plano: Trio Activo / Terminal Activo
        // [{"resultado1":"486","time_s":"08:00 AM","fecha":"...","id":"..."}, ...]
        if ($esFormatoPlano) {
            $gameName = $this->slug === 'trio_activo' ? 'Trío Activo' : 'Terminal Activo';
            $juego = $this->findJuegoOrFail(['name' => $gameName]);

            foreach ($data['datos'] as $item) {
                $resultados[] = $this->mapToResultado($item, $juego, $item);
            }

            return $resultados;
        }

        // Formato anidado: Animalitos
        // [{"name":"Lotto Activo","resultados":[{...}]}, ...]
        foreach ($data['datos'] as $juegoData) {
            $juego = $this->findJuegoOrFail($juegoData);

            foreach ($juegoData['resultados'] ?? [] as $resultadoData) {
                $resultados[] = $this->mapToResultado($resultadoData, $juego, $juegoData);
            }
        }

        return $resultados;
    }

    protected function extractToken(string $html): ?string
    {
        $crawler = $this->createCrawler($html);

        $scriptContent = $crawler->filter('script')->each(function ($node) {
            return $node->text();
        });

        if (in_array($this->slug, ['trio_activo', 'terminal_activo'])) {
            // Extraer el token del data que incluye fecha (el de resultados, no el de metadata)
            foreach ($scriptContent as $script) {
                if (preg_match("/data\s*=\s*\{[^}]*'option'\s*:\s*'([^']+)'[^}]*'fecha'/s", $script, $matches)) {
                    return $matches[1];
                }
            }
        } else {
            // Primera ocurrencia (metadata + resultados comparten el mismo token en animalitos)
            foreach ($scriptContent as $script) {
                if (preg_match("/data\s*=\s*\{[^}]*'option'\s*:\s*'([^']+)'/", $script, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }

    protected function findJuegoOrFail(array $juegoData): Juego
    {
        $name = $juegoData['name'] ?? null;

        if (! $name) {
            throw new \RuntimeException('Nombre de juego ausente en el feed');
        }

        $rawSlug = \Str::slug($name);

        $canonicalSlug = match ($rawSlug) {
            'lotto-activo-2-monje-millonario', 'lottoactivo2-monjemillonario' => 'monje-millonario',
            'terminal-trio' => 'terminal-activo',
            'lotto-activo-rd-internacional' => 'lotto-activo-rd',
            'lotto-activo-republica-dominicana' => 'lotto-activo-rep-dom',
            'lotto-activo' => 'lotto-activo',
            default => $rawSlug,
        };

        return parent::findJuegoOrFail(['slug' => $canonicalSlug, 'name' => $name]);
    }

    protected function mapToResultado(array $data, Juego $juego, array $juegoData): array
    {
        $esFormatoPlano = isset($data['resultado1']) && ! isset($data['number_animal']);

        if ($esFormatoPlano) {
            return $this->mapFlatResult($data, $juego);
        }

        // Formato anidado: Animalitos
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
