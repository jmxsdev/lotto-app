<?php

namespace App\Plugins\Scrapers;

/**
 * Lotto Activo page → Animalitos game (nested format).
 * Extracts only the "Lotto Activo" section of the page response.
 */
class LottoActivoAnimalitosScraper extends LottoActivoBaseScraper
{
    protected array $acceptedSlugs = ['lotto-activo'];

    public function __construct(?string $pageSlug = null)
    {
        parent::__construct($pageSlug ?? 'animalitos');
    }

    public function parse(string $rawData): array
    {
        $data = $this->decodeJson($rawData);

        if (! isset($data['datos']) || ! is_array($data['datos'])) {
            $this->logWarning('Respuesta sin campo "datos" o vacío');

            return [];
        }

        $resultados = [];

        foreach ($data['datos'] as $juegoData) {
            $juego = $this->findOrCreateJuego($juegoData);

            if (! $juego) {
                continue;
            }

            foreach ($juegoData['resultados'] ?? [] as $resultadoData) {
                $resultados[] = $this->mapAnimalitoResult($resultadoData, $juego, $juegoData);
            }
        }

        return $resultados;
    }
}
