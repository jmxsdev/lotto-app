<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use Illuminate\Support\Carbon;

/**
 * Triple Zulia page → Triple Zulia game (API product endpoint).
 */
class TripleZuliaScraper extends BaseScraper
{
    protected string $baseUrl = 'https://resultadostriplezulia.com';
    protected string $scraperName = 'TripleZuliaScraper';
    protected string $productId = '2';

    public function execute(string $fecha = null): array
    {
        if (! $fecha) {
            $fecha = now()->format('Y-m-d');
        }

        $this->logInfo("Iniciando scrape para fecha: {$fecha}");

        try {
            $rawData = $this->fetch($fecha);
            $todosResultados = $this->parse($rawData);

            $resultados = array_values(array_filter($todosResultados, function ($r) use ($fecha) {
                return $r['fecha_sorteo'] === $fecha;
            }));

            $this->logInfo('Scrape completado. '.count($resultados)." resultados para {$fecha} (descartados ".(count($todosResultados) - count($resultados)).' históricos)');

            return $resultados;
        } catch (\Exception $e) {
            $this->logError('Error en scrape: '.$e->getMessage(), [
                'exception' => get_class($e),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            throw $e;
        }
    }

    protected function fetch(string $fecha): string
    {
        return $this->postJsonPayload(
            $this->baseUrl.'/api/gaming/results/product',
            ['game_product_id' => $this->productId]
        );
    }

    public function parse(string $rawData): array
    {
        $data = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar JSON: '.json_last_error_msg());
        }

        $juego = $this->findOrCreateJuego();

        $resultados = [];

        foreach ($data['response'] as $item) {
            $timestamp = $item['event_timestamp']['seconds'];
            $fechaSorteo = Carbon::createFromTimestampUTC($timestamp)->format('Y-m-d');
            $horaSorteo = Carbon::createFromTimestampUTC($timestamp)
                ->setTimezone('America/Caracas')
                ->format('H:i');

            $numeros = [];
            foreach ($item['results'] as $result) {
                $key = array_key_first($result);
                $value = $result[$key];

                if ($key === 'C') {
                    $parts = explode('-', $value, 2);
                    $numeros['triple_c'] = $parts[0];
                    $numeros['signo'] = $parts[1] ?? null;
                } else {
                    $numeros['triple_'.strtolower($key)] = $value;
                }
            }

            $numeros['pais'] = 'VE';

            $resultados[] = [
                'juego_id' => $juego->id,
                'fecha_sorteo' => $fechaSorteo,
                'hora_sorteo' => $horaSorteo,
                'numeros_ganadores' => $numeros,
                'sorteo_id_externo' => (string) ($item['events'][0] ?? null),
                'premios_detalle' => null,
            ];
        }

        return $resultados;
    }

    protected function findOrCreateJuego(): Juego
    {
        return Juego::firstOrCreate(
            ['slug' => 'triple-zulia'],
            [
                'name' => 'Triple Zulia',
                'type' => 'tripletas',
                'config' => ['premio_multiplo' => $this->defaultMultiplier()],
                'requires_scraper' => true,
                'scraper_url' => $this->baseUrl.'/',
                'active' => true,
            ]
        );
    }
}
