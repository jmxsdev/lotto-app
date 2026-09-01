<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper parametrizado para la fuente loteriadehoy.com.
 *
 * Reutilizable por cualquier juego del sitio: se parametriza con el Juego
 * registrado (usa su scraper_url para fetch y su slug/name para fail-fast).
 * Formato soportado: tabla de resultados de triples (hora en 12h, A/B/C y
 * signo zodiacal), común a los juegos /loteria/{slug}/ del sitio.
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

    protected function extraerNumero(string $celda): ?string
    {
        if (preg_match('/\d+/', $celda, $matches)) {
            return $matches[0];
        }

        return null;
    }
}
