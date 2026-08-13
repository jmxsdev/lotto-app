<?php

namespace App\Plugins\Scrapers;

/**
 * Lotto Activo page → Trío Activo game (flat format).
 */
class LottoActivoTrioActivoScraper extends LottoActivoBaseScraper
{
    protected string $gameType = 'tripletas';

    protected array $acceptedSlugs = ['trio-activo'];

    public function __construct(?string $pageSlug = null)
    {
        parent::__construct($pageSlug ?? 'trio_activo');
    }

    public function parse(string $rawData): array
    {
        $data = $this->decodeJson($rawData);

        if (! isset($data['datos']) || ! is_array($data['datos'])) {
            $this->logWarning('Respuesta sin campo "datos" o vacío');

            return [];
        }

        $juego = $this->findOrCreateJuegoByName('Trío Activo', 'trio-activo');

        if (! $juego) {
            $this->logWarning('No se pudo encontrar/crear juego: Trío Activo');

            return [];
        }

        $resultados = [];

        foreach ($data['datos'] as $item) {
            if (! isset($item['resultado1'])) {
                continue;
            }

            $resultados[] = $this->mapFlatResult($item, $juego);
        }

        return $resultados;
    }
}
