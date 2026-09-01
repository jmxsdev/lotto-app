<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;

/**
 * Scraper dedicado para El Arrejuntado (API JSON de serviciosintegradostriple7.com).
 *
 * El endpoint devuelve por fecha una lista de `draws`; cada draw publicado
 * (`is_published=true`) trae 6 modalidades: animalito, el-arrimao, el-pegadito,
 * triple-a, triple-b y triple-signo. El juego se registra con type `tripletas`
 * (según la tabla del cliente) y cada draw se persiste como UN resultado cuya
 * `numeros_ganadores` (array JSON flexible) conserva las 6 modalidades:
 *   - animalito / arrimao / pegadito: modalidades extra no consumidas por la
 *     renderización tripletas del frontend, pero se conservan en el array.
 *   - triple-a → triple_a, triple-b → triple_b.
 *   - triple-signo "259 LEO" se divide en triple_c ("259") + signo ("LEO") para
 *     ser compatible con el esquema tripletas que renderiza el panel.
 */
class ElArrejuntaoScraper extends BaseScraper
{
    protected string $scraperName = 'ElArrejuntaoScraper';

    protected ?Juego $juego;

    public function __construct(?Juego $juego = null)
    {
        parent::__construct();
        $this->juego = $juego;
    }

    public function fetch(string $fecha): string
    {
        $base = rtrim((string) $this->juego?->scraper_url, '/');

        if (! $base) {
            throw new \RuntimeException('ElArrejuntaoScraper requiere un juego con scraper_url; ejecuta su seeder');
        }

        $url = $base.'/?date='.$fecha;

        return $this->getJson($url);
    }

    public function parse(string $rawData): array
    {
        $data = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar JSON: '.json_last_error_msg());
        }

        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        $resultados = [];

        foreach ($data['draws'] ?? [] as $draw) {
            if (empty($draw['is_published'])) {
                continue;
            }

            $numeros = $this->mapResultados($draw['results'] ?? []);

            // Un draw publicado sin ninguna modalidad no genera fila
            // (el array solo traería la clave fija `pais`).
            if (count($numeros) <= 1) {
                continue;
            }

            $resultados[] = [
                'juego_id' => $juego->id,
                'hora_sorteo' => $this->normalizeHora($draw['draw_time'] ?? null),
                'numeros_ganadores' => $numeros,
                'sorteo_id_externo' => $draw['id'] ?? null,
                'premios_detalle' => null,
            ];
        }

        return $resultados;
    }

    /**
     * Mapea las 6 modalidades del draw a las claves del array numeros_ganadores.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, string|null>
     */
    protected function mapResultados(array $results): array
    {
        $numeros = ['pais' => 'VE'];

        foreach ($results as $result) {
            $slug = $result['game']['slug'] ?? null;
            $valor = $result['result_value'] ?? null;

            if (! $slug || $valor === null) {
                continue;
            }

            switch ($slug) {
                case 'triple-a':
                    $numeros['triple_a'] = $valor;
                    break;
                case 'triple-b':
                    $numeros['triple_b'] = $valor;
                    break;
                case 'triple-signo':
                    [$triple, $signo] = $this->splitTripleSigno((string) $valor);
                    $numeros['triple_c'] = $triple;
                    $numeros['signo'] = $signo;
                    break;
                case 'animalito':
                    $numeros['animalito'] = $valor;
                    break;
                case 'el-arrimao':
                    $numeros['arrimao'] = $valor;
                    break;
                case 'el-pegadito':
                    $numeros['pegadito'] = $valor;
                    break;
            }
        }

        return $numeros;
    }

    /**
     * Divide el valor de triple-signo "259 LEO" en número (259) y signo (LEO).
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function splitTripleSigno(string $valor): array
    {
        if (preg_match('/^(\d+)\s+([A-Za-z]+)$/', trim($valor), $matches)) {
            return [$matches[1], strtoupper($matches[2])];
        }

        return [$valor, null];
    }

    protected function getJson(string $url): string
    {
        $response = $this->client->get($url, [
            'headers' => ['Accept' => 'application/json'],
        ]);

        $body = (string) $response->getBody();
        $this->logInfo("JSON obtenido de {$url}, longitud: ".strlen($body));

        return $body;
    }
}
