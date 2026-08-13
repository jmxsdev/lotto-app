<?php

namespace App\Plugins\Scrapers;

/**
 * Lotto Activo page → Terminal Activo game (flat format).
 */
class LottoActivoTerminalActivoScraper extends LottoActivoBaseScraper
{
    protected string $gameType = 'terminales';

    protected array $acceptedSlugs = ['terminal-activo'];

    public function __construct(?string $pageSlug = null)
    {
        parent::__construct($pageSlug ?? 'terminal_activo');
    }

    public function parse(string $rawData): array
    {
        $data = $this->decodeJson($rawData);

        if (! isset($data['datos']) || ! is_array($data['datos'])) {
            $this->logWarning('Respuesta sin campo "datos" o vacío');

            return [];
        }

        $juego = $this->findOrCreateJuegoByName('Terminal Activo', 'terminal-activo');

        if (! $juego) {
            $this->logWarning('No se pudo encontrar/crear juego: Terminal Activo');

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
