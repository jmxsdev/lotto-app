<?php

namespace App\Services;

/**
 * Fuente de scrape consolidada: un feed = un fetch = una fuente.
 *
 * La familia lottoactivo (lotto-activo, lotto-activo-rd,
 * lotto-activo-rep-dom, monje-millonario) comparte el feed `animalitos` y se
 * consolida en la fuente `lottoactivo-animalitos`; trio/terminal tienen feed
 * propio con slug distinto; el resto de juegos tiene 1 fuente por juego.
 */
final readonly class ScraperSource
{
    /**
     * @param  int[]  $juegoIds
     */
    public function __construct(
        public string $key,
        public ?string $scraperClass,
        public ?string $slug,
        public array $juegoIds = [],
    ) {}
}
