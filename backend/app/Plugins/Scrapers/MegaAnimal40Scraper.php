<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper dedicado para Mega Animal 40 usando el proveedor agregador
 * resultadosvenezuela.com (HTML server-rendered; sin API JSON pública).
 *
 * Endpoint:
 *
 *   GET https://resultadosvenezuela.com/lottery/mega-animal-40            → día en curso (parcial)
 *   GET https://resultadosvenezuela.com/lottery/mega-animal-40?date=YYYY-MM-DD → fecha pasada (completo)
 *
 * Estructura de resultados (cards, verificada con capturas reales 2026-09-11/12/13):
 *
 *   <div class="result-card">
 *     <div class="card-time">08:00 PM</div>
 *     <div class="card-number">16</div>
 *     <div class="card-name">Oso</div>
 *     <div class="card-date">11/09/2026</div>
 *   </div>
 *
 * Decisiones:
 * - El sitio SOLO renderiza horas ya sorteadas (el día en curso es parcial); una fecha sin
 *   sorteos ocurridos renderiza la página completa SIN cards ("No se encontraron sorteos..."),
 *   un estado VÁLIDO → `parse` devuelve `[]` (sin resultados). Solo un cuerpo vacío/inexistente
 *   es un error (RuntimeException, fail-fast).
 * - `card-time` viene en 12h ("08:00 PM") y se normaliza a "H:i" con `normalizeHora`
 *   del BaseScraper ("08:00 PM" → "20:00").
 * - `card-number` y `card-name` son el número y el nombre del animal (zoológico canónico de 38:
 *   Delfín/Ballena 0, Carnero 1 ... Culebra 36). `numeros_ganadores` = {pais VE, numero, nombre_animal}.
 * - Defensivo: una card sin `card-number` (p. ej. los marcadores "Pendiente" 🕒 que el
 *   proveedor usa en juegos de triples, o cambios leves del markup) se SALTA, no rompe el parse.
 * - El comodín "MEGA" (premio 40x automático) NO aparece como marcador en las cards
 *   (~11 fechas escaneadas: 11..3-sep + 12-sep parcial + 13-sep): las ocurrencias de "MEGA"
 *   en el HTML son el nombre del juego y la ruta de las imágenes (`mega-animal-40/`).
 *   Hallazgo documentado en docs/plataformas-juegos.md; NO se implementa detección de comodín.
 * - Sin ID externo por sorteo → `sorteo_id_externo` null y dedupe por juego+fecha+hora
 *   en `saveResults` heredado.
 */
class MegaAnimal40Scraper extends BaseScraper
{
    protected string $scraperName = 'MegaAnimal40Scraper';

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
            throw new \RuntimeException('MegaAnimal40Scraper requiere un juego con scraper_url; ejecuta su seeder');
        }

        // La scraper_url documenta la página sin query. La query real de fecha
        // se construye aquí, ignorando cualquier query documental.
        $partes = parse_url($base);
        $url = ($partes['scheme'] ?? 'https').'://'.($partes['host'] ?? '').($partes['path'] ?? '');

        return $this->getHtml($url.'?date='.$fecha);
    }

    public function parse(string $rawData): array
    {
        // Cuerpo vacío/inexistente: respuesta inválida (fail-fast). Una página
        // VÁLIDA sin cards (fecha sin sorteos ocurridos) devuelve [] más abajo.
        if (trim($rawData) === '') {
            throw new \RuntimeException('Respuesta vacía del proveedor resultadosvenezuela.com');
        }

        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        $crawler = $this->createCrawler($rawData);
        $cards = $crawler->filter('.result-card');

        // Día sin sorteos ocurridos aún: estado válido, sin resultados.
        if ($cards->count() === 0) {
            return [];
        }

        $resultados = [];

        $cards->each(function (Crawler $card) use (&$resultados, $juego): void {
            $timeNode = $card->filter('.card-time');
            $numberNode = $card->filter('.card-number');
            $nameNode = $card->filter('.card-name');

            // Card "Pendiente" o markup incompleto: se salta (defensivo).
            if ($timeNode->count() === 0 || $numberNode->count() === 0 || $nameNode->count() === 0) {
                return;
            }

            $hora = $this->normalizeHora($timeNode->text());
            $numero = (int) trim($numberNode->text());
            $nombreAnimal = trim($nameNode->text());

            // Hora no parseable: se salta (defensivo ante cambios del markup).
            if ($hora === null || $nombreAnimal === '') {
                return;
            }

            $resultados[] = [
                'juego_id' => $juego->id,
                'hora_sorteo' => $hora,
                'numeros_ganadores' => [
                    'pais' => 'VE',
                    'numero' => $numero,
                    'nombre_animal' => $nombreAnimal,
                ],
                'sorteo_id_externo' => null,
                'premios_detalle' => null,
            ];
        });

        return $resultados;
    }
}
