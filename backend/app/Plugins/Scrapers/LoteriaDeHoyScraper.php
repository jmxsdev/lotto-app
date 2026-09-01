<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper parametrizado para la fuente loteriadehoy.com.
 *
 * Reutilizable por cualquier juego del sitio: se parametriza con el Juego
 * registrado (usa su scraper_url para fetch y su slug/name para fail-fast).
 *
 * Formato soportado según el type del juego:
 * - tripletas: tabla de resultados de triples (hora en 12h, A/B/C y signo
 *   zodiacal), común a los juegos /loteria/{slug}/ del sitio.
 * - animalitos: bloques de resultado con número + animal + hora (12h) dentro
 *   de `div.js-con`, común a los juegos /animalito/{slug}/. La página solo
 *   renderiza los sorteos ya ocurridos del día, por lo que se manejan
 *   resultados parciales (los bloques presentes, sin asumir el total).
 */
class LoteriaDeHoyScraper extends BaseScraper
{
    protected string $scraperName = 'LoteriaDeHoyScraper';

    protected ?Juego $juego;

    public function __construct(?Juego $juego = null)
    {
        parent::__construct();
        $this->juego = $juego;
    }

    public function fetch(string $fecha): string
    {
        $url = rtrim((string) $this->juego?->scraper_url, '/');

        if (! $url) {
            throw new \RuntimeException('LoteriaDeHoyScraper requiere un juego con scraper_url; ejecuta su seeder');
        }

        return $this->getHtml($url.'/'.$fecha.'/');
    }

    public function parse(string $rawData): array
    {
        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        if ($juego->type === 'animalitos') {
            return $this->parseAnimalitos($rawData, $juego);
        }

        return $this->parseTripletas($rawData, $juego);
    }

    /**
     * Parse de resultados de tipo animalitos (bloques con número + animal + hora).
     *
     * Estructura real (por bloque en `div.row.js-con`):
     *   <h4 class="mt-3 rojo">5 Leon</h4>
     *   <h5>Cazaloton 09:00 AM</h5>
     * Solo se procesan los bloques presentes: la página no renderiza los
     * sorteos aún no ocurridos del día (resultados parciales).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseAnimalitos(string $rawData, Juego $juego): array
    {
        $crawler = $this->createCrawler($rawData);
        $bloques = $crawler->filter('div.js-con div.mb-5');

        $resultados = [];

        foreach ($bloques as $bloque) {
            $node = new Crawler($bloque);
            $numeroAnimal = trim($node->filter('h4')->first()->text());
            $horaLinea = trim($node->filter('h5')->first()->text());

            $hora = $this->normalizeHora($this->extraerHora($horaLinea));
            $numero = $this->extraerNumero($numeroAnimal);
            $animal = $this->extraerAnimal($numeroAnimal);

            if (! $hora || ! $numero || ! $animal) {
                continue;
            }

            $resultados[] = [
                'juego_id' => $juego->id,
                'hora_sorteo' => $hora,
                'numeros_ganadores' => [
                    'numero' => (int) $numero,
                    'nombre_animal' => $animal,
                    'pais' => 'VE',
                ],
                'sorteo_id_externo' => null,
                'premios_detalle' => null,
            ];
        }

        return $resultados;
    }

    /**
     * Parse de resultados de tipo tripletas (tabla A/B/C + signo).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function parseTripletas(string $rawData, Juego $juego): array
    {
        $crawler = $this->createCrawler($rawData);
        $filas = $crawler->filter('table.resultados tbody tr');

        $resultados = [];

        foreach ($filas as $fila) {
            $celdas = (new Crawler($fila))
                ->filter('td')
                ->each(fn ($td) => trim($td->text()));

            if (count($celdas) < 5) {
                continue;
            }

            $hora = $this->normalizeHora($celdas[0]);
            $tripleA = $this->extraerNumero($celdas[1]);
            $tripleB = $this->extraerNumero($celdas[2]);
            $tripleC = $this->extraerNumero($celdas[3]);

            if (! $hora || ! $tripleA || ! $tripleB || ! $tripleC) {
                continue;
            }

            $signo = strtoupper($celdas[4]);

            $resultados[] = [
                'juego_id' => $juego->id,
                'hora_sorteo' => $hora,
                'numeros_ganadores' => [
                    'triple_a' => $tripleA,
                    'triple_b' => $tripleB,
                    'triple_c' => $tripleC,
                    'signo' => $signo ?: null,
                    'pais' => 'VE',
                ],
                'sorteo_id_externo' => null,
                'premios_detalle' => null,
            ];
        }

        return $resultados;
    }

    /**
     * Extrae la hora en formato 12h de la línea de cabecera del bloque
     * (p.ej. "Cazaloton 09:00 AM" → "09:00 AM").
     */
    protected function extraerHora(string $linea): ?string
    {
        if (preg_match('/(\d{1,2}:\d{2}\s*[AP]M)/i', $linea, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extrae el nombre del animal del texto "5 Leon" → "Leon".
     */
    protected function extraerAnimal(string $texto): ?string
    {
        if (preg_match('/^\d+\s+(.+)$/', trim($texto), $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    protected function extraerNumero(string $celda): ?string
    {
        if (preg_match('/\d+/', $celda, $matches)) {
            return $matches[0];
        }

        return null;
    }
}
