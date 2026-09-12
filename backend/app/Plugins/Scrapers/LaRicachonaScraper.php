<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper dedicado para La Ricachona usando el HTML server-rendered por fecha
 * del portal laricachona.com (sin API pública):
 *
 *   GET https://laricachona.com/            → resultados de HOY
 *   GET https://laricachona.com/?date=YYYY-MM-DD → resultados de esa fecha
 *
 * Los sorteos de triples están en artículos `tripleResultArticle`:
 *
 *   <article class='tripleResultArticle'><h1>08:05 AM</h1>
 *     <p>29</p><p>030</p><p>31</p></article>
 *
 * Decisiones:
 * - El `<p>` del MEDIO es el número de 3 dígitos del sorteo ("030", cero
 *   inicial conservado como STRING); los laterales son decorativos
 *   (derivados -1/+1 del último par) y NO se guardan.
 * - La hora del sorteo está en el `<h1>` en formato 12h ("08:05 AM") y se
 *   normaliza a "H:i" con `normalizeHora` del BaseScraper.
 * - Sorteos NO ocurridos: los 3 `<p>` vienen como `--`/`---` → se saltan
 *   (resultados parciales del día, patrón loteriadehoy/lagranjita).
 * - El portal también renderiza la sección `animalsResultArticle` (La Ricachona
 *   animalitos, fuera de alcance): el selector filtra SOLO `tripleResultArticle`.
 * - Sin ID externo por sorteo en este sitio → `sorteo_id_externo` null y dedupe
 *   por juego+fecha+hora en `saveResults` heredado.
 * - HTML vacío o sin artículos de triples → RuntimeException (fail-fast).
 */
class LaRicachonaScraper extends BaseScraper
{
    protected string $scraperName = 'LaRicachonaScraper';

    protected ?Juego $juego;

    public function __construct(?Juego $juego = null)
    {
        parent::__construct();
        $this->juego = $juego;
    }

    public function fetch(string $fecha): string
    {
        $base = (string) $this->juego?->scraper_url;

        if (! $base) {
            throw new \RuntimeException('LaRicachonaScraper requiere un juego con scraper_url; ejecuta su seeder');
        }

        // La scraper_url documenta el portal sin query (https://laricachona.com/).
        // La query real se construye aquí con la fecha solicitada.
        $partes = parse_url($base);
        $url = ($partes['scheme'] ?? 'https').'://'.($partes['host'] ?? '').($partes['path'] ?? '');

        return $this->getHtml($url.'?date='.$fecha);
    }

    public function parse(string $rawData): array
    {
        if (trim($rawData) === '') {
            throw new \RuntimeException('Respuesta vacía del portal de La Ricachona');
        }

        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        $crawler = $this->createCrawler($rawData);
        $articulos = $crawler->filter('article.tripleResultArticle');

        if ($articulos->count() === 0) {
            throw new \RuntimeException('HTML sin sorteos de La Ricachona (sin artículos tripleResultArticle)');
        }

        $resultados = [];

        $articulos->each(function (Crawler $articulo) use (&$resultados, $juego): void {
            $horaTexto = trim($articulo->filter('h1')->first()->text());
            $parrafos = $articulo->filter('p');

            if ($parrafos->count() < 3) {
                return;
            }

            // El p del MEDIO (índice 1) es el número de 3 dígitos del sorteo.
            $numero = trim($parrafos->eq(1)->text());

            // Sorteo no ocurrido: "--" / "---".
            if ($numero === '--' || $numero === '---') {
                return;
            }

            $resultados[] = [
                'juego_id' => $juego->id,
                'hora_sorteo' => $this->normalizeHora($horaTexto),
                'numeros_ganadores' => [
                    'triple_a' => $numero,
                ],
                'sorteo_id_externo' => null,
                'premios_detalle' => null,
            ];
        });

        return $resultados;
    }
}
