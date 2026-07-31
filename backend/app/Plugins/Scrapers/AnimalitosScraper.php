<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use App\Models\Resultado;
use Illuminate\Support\Carbon;

class AnimalitosScraper extends BaseScraper
{
    protected string $baseUrl = 'https://www.lottoactivo.com';
    protected string $scraperName = 'AnimalitosScraper';
    protected string $slug;

    public function __construct(string $slug = 'animalitos')
    {
        parent::__construct();
        $this->slug = $slug;
        $this->scraperName = \Str::studly($slug) . 'Scraper';
    }

    public function fetch(string $fecha): string
    {
        $url = $this->baseUrl . '/resultados/' . $this->slug . '/' . $fecha . '/';
        $html = $this->getHtml($url);
        
        $token = $this->extractToken($html);
        
        if (!$token) {
            throw new \RuntimeException('No se pudo extraer el token de la página');
        }
        
        $postData = [
            'option' => $token,
            'loteria' => $this->slug,
            'fecha' => $fecha,
        ];
        
        return $this->client->post($this->baseUrl . '/core/process.php', [
            'form_params' => $postData,
            'headers' => [
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json',
                'Referer' => $url,
            ]
        ])->getBody()->getContents();
    }

    public function parse(string $rawData): array
    {
        $data = json_decode($rawData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar JSON: ' . json_last_error_msg());
        }
        
        if (!isset($data['datos']) || !is_array($data['datos'])) {
            $this->logWarning('Respuesta sin campo "datos" o vacío');
            return [];
        }
        
        $resultados = [];
        $primerItem = $data['datos'][0] ?? null;
        $esFormatoPlano = $primerItem && !isset($primerItem['name']) && !isset($primerItem['resultados']);

        // Formato plano: Trio Activo / Terminal Activo
        // [{"resultado1":"486","time_s":"08:00 AM","fecha":"...","id":"..."}, ...]
        if ($esFormatoPlano) {
            $gameName = $this->slug === 'trio_activo' ? 'Trío Activo' : 'Terminal Activo';
            $juego = $this->findOrCreateJuego(['name' => $gameName]);

            if (!$juego) {
                $this->logWarning("No se pudo encontrar/crear juego: {$gameName}");
                return [];
            }

            foreach ($data['datos'] as $item) {
                $resultados[] = $this->mapToResultado($item, $juego, $item);
            }
            return $resultados;
        }

        // Formato anidado: Animalitos
        // [{"name":"Lotto Activo","resultados":[{...}]}, ...]
        foreach ($data['datos'] as $juegoData) {
            $juego = $this->findOrCreateJuego($juegoData);
            
            if (!$juego) {
                $this->logWarning("No se pudo encontrar/crear juego: " . ($juegoData['name'] ?? 'desconocido'));
                continue;
            }
            
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

    protected function findOrCreateJuego(array $juegoData): ?Juego
    {
        $name = $juegoData['name'] ?? null;
        
        if (!$name) {
            return null;
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

        $type = match ($this->slug) {
            'trio_activo' => 'tripletas',
            'terminal_activo' => 'terminales',
            default => 'animalitos',
        };

        $existing = Juego::where('slug', $canonicalSlug)->first();
        if ($existing) {
            return $existing;
        }

        return Juego::create([
            'slug' => $canonicalSlug,
            'name' => $name,
            'type' => $type,
            'config' => ['premio_multiplo' => 30],
            'requires_scraper' => true,
            'scraper_url' => $this->baseUrl . '/resultados/' . $this->slug . '/',
            'active' => true,
        ]);
    }

    protected function mapToResultado(array $data, Juego $juego, array $juegoData): array
    {
        $esFormatoPlano = isset($data['resultado1']) && !isset($data['number_animal']);

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
                'numero' => (int)($data['number_animal'] ?? 0),
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
            $numeros['numero'] = (int)($data['resultado1'] ?? 0);
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

    public function saveResults(array $resultados, string $fecha): int
    {
        $guardados = 0;
        
        foreach ($resultados as $resultadoData) {
            $resultadoData['fecha_sorteo'] = $fecha;
            
            $existing = Resultado::where('juego_id', $resultadoData['juego_id'])
                ->whereDate('fecha_sorteo', $fecha)
                ->where('hora_sorteo', $resultadoData['hora_sorteo'])
                ->first();
            
            if ($existing) {
                $existing->update($resultadoData);
                $this->logInfo("Resultado actualizado: hora {$resultadoData['hora_sorteo']}");
            } else {
                Resultado::create($resultadoData);
                $this->logInfo("Resultado creado: hora {$resultadoData['hora_sorteo']}");
            }
            
            $guardados++;
        }
        
        return $guardados;
    }
}
