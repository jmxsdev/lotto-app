<?php

namespace App\Plugins\Scrapers;

/**
 * Lotto Activo page → Monje Millonario game (nested format, same page as
 * Animalitos, different section of the response).
 */
class LottoActivoMonjeMillonarioScraper extends LottoActivoBaseScraper
{
    protected array $acceptedSlugs = ['monje-millonario'];

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
