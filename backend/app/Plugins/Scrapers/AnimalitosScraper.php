<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use App\Models\Resultado;
use Illuminate\Support\Carbon;

class AnimalitosScraper extends BaseScraper
{
    protected string $baseUrl = 'https://www.lottoactivo.com';
    protected string $scraperName = 'AnimalitosScraper';

    public function fetch(string $fecha): string
    {
        $url = $this->baseUrl . '/resultados/animalitos/' . $fecha . '/';
        $html = $this->getHtml($url);
        
        $token = $this->extractToken($html);
        
        if (!$token) {
            throw new \RuntimeException('No se pudo extraer el token de la página');
        }
        
        $postData = [
            'option' => $token,
            'loteria' => 'animalitos',
            'fecha' => $fecha,
        ];
        
        return $this->postJson($this->baseUrl . '/core/process.php', $postData);
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
        
        foreach ($scriptContent as $script) {
            if (preg_match("/data\s*=\s*\{[^}]*'option'\s*:\s*'([^']+)'/", $script, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }

    protected function findOrCreateJuego(array $juegoData): ?Juego
    {
        $name = $juegoData['name'] ?? null;
        $link = $juegoData['link'] ?? null;
        
        if (!$name) {
            return null;
        }
        
        $slug = $link ?: \Str::slug($name);
        
        return Juego::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'type' => 'animalitos',
                'config' => json_encode(['premio_multiplo' => 30]),
                'requires_scraper' => true,
                'scraper_url' => $this->baseUrl . '/resultados/animalitos/',
                'active' => true,
            ]
        );
    }

    protected function mapToResultado(array $data, Juego $juego, array $juegoData): array
    {
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
