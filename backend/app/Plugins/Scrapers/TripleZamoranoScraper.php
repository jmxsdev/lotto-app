<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use Illuminate\Support\Carbon;

/**
 * Scraper dedicado para Triple Zamorano usando la API oficial de triplezamorano.com.
 *
 * Triple Zamorano comparte casa/plataforma con Triple Caliente (misma familia de
 * API, productId distinto), sin auth ni anti-bot:
 *
 *   POST https://www.triplezamorano.com/api/gaming/results/product
 *   Headers: Content-Type: application/json (sin auth)
 *   Body: {"game_product_id":"1"}
 *
 * La respuesta trae el histórico de sorteos del juego (los últimos N, ~386
 * entradas, más recientes primero):
 *   {"status":201,"response":[{"events":[135033,135034],"results":[{"A":"683"},
 *   {"C":"452-ARI"}],"event_timestamp":{"seconds":1789243200}},...]}
 *
 * Decisiones:
 * - `game_product_id`: constante `GAME_PRODUCT_ID` ('1') como default, con override
 *   vía `config['scraper']['product_id']` del juego registrado (misma convención
 *   D6 del diseño y patrón TripleCalienteOficialScraper).
 * - Resultados: SOLO A y C (NO hay B). `A` = triple de 3 cifras; `C` = triple de
 *   3 cifras + signo (sufijo tras el guion, p.ej. "452-ARI"). El array `results`
 *   trae `[{"A":...},{"C":...}]`.
 * - Fecha y hora local se derivan de `event_timestamp.seconds` en America/Caracas
 *   (UTC-4), normalizando `hora_sorteo` a "H:i" y `fecha_sorteo` a "Y-m-d".
 *   Horarios oficiales verificados con los timestamps: 5 sorteos diarios
 *   10:00/12:00/14:00/16:00/19:00 (domingos solo 19:00).
 * - C "452-ARI" se divide en triple_c ("452") + signo ("ARI") para el esquema
 *   tripletas del frontend (mismo splitTripleSigno de TripleCalienteOficialScraper).
 * - `sorteo_id_externo` usa el primer id del array `events` (2 ids por sorteo).
 * - La API devuelve el histórico completo: `execute` filtra por la fecha solicitada
 *   (patrón TripletasScraper/TripleCalienteOficialScraper, misma familia de API)
 *   antes de `saveResults`.
 */
class TripleZamoranoScraper extends BaseScraper
{
    protected string $scraperName = 'TripleZamoranoScraper';

    protected const GAME_PRODUCT_ID = '1';

    protected ?Juego $juego;

    protected string $productId;

    public function __construct(?Juego $juego = null)
    {
        parent::__construct();
        $this->juego = $juego;
        $this->productId = (string) ($juego?->config['scraper']['product_id'] ?? self::GAME_PRODUCT_ID);
    }

    public function execute(?string $fecha = null): array
    {
        if (! $fecha) {
            $fecha = now()->format('Y-m-d');
        }

        $this->logInfo("Iniciando scrape para fecha: {$fecha}");

        try {
            $rawData = $this->fetch($fecha);
            $todosResultados = $this->parse($rawData);

            $resultados = $this->filtrarPorFecha($todosResultados, $fecha);

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

    public function fetch(string $fecha): string
    {
        $url = rtrim((string) $this->juego?->scraper_url, '/');

        if (! $url) {
            throw new \RuntimeException('TripleZamoranoScraper requiere un juego con scraper_url; ejecuta su seeder');
        }

        return $this->postJsonPayload($url, ['game_product_id' => $this->productId]);
    }

    public function parse(string $rawData): array
    {
        $data = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar JSON: '.json_last_error_msg());
        }

        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        $resultados = [];

        foreach ($data['response'] ?? [] as $item) {
            $timestamp = $item['event_timestamp']['seconds'] ?? null;

            if (! $timestamp) {
                continue;
            }

            $fechaHora = Carbon::createFromTimestampUTC((int) $timestamp)
                ->setTimezone('America/Caracas');

            $resultados[] = [
                'juego_id' => $juego->id,
                'fecha_sorteo' => $fechaHora->format('Y-m-d'),
                'hora_sorteo' => $fechaHora->format('H:i'),
                'numeros_ganadores' => $this->mapNumeros($item['results'] ?? []),
                'sorteo_id_externo' => (string) ($item['events'][0] ?? null),
                'premios_detalle' => null,
            ];
        }

        return $resultados;
    }

    /**
     * Filtra los resultados parseados a los de la fecha solicitada.
     * La API devuelve el histórico completo; execute descarta los históricos.
     *
     * @param  array<int, array<string, mixed>>  $resultados
     * @return array<int, array<string, mixed>>
     */
    protected function filtrarPorFecha(array $resultados, string $fecha): array
    {
        return array_values(array_filter(
            $resultados,
            fn ($r) => ($r['fecha_sorteo'] ?? null) === $fecha
        ));
    }

    /**
     * Mapea los resultados A/C del sorteo al esquema tripletas del frontend.
     *
     * La API oficial de Triple Zamorano SOLO expone A y C (NO hay B):
     * - A: triple de 3 cifras → triple_a
     * - C: triple de 3 cifras + signo ("452-ARI") → triple_c + signo
     *
     * @param  array<int, array<string, string>>  $results
     * @return array<string, string|null>
     */
    protected function mapNumeros(array $results): array
    {
        $numeros = ['pais' => 'VE'];

        foreach ($results as $result) {
            $key = array_key_first($result);
            $valor = $result[$key];

            if ($key === 'C') {
                [$triple, $signo] = $this->splitTripleSigno((string) $valor);
                $numeros['triple_c'] = $triple;
                $numeros['signo'] = $signo;
            } else {
                $numeros['triple_'.strtolower((string) $key)] = $valor;
            }
        }

        return $numeros;
    }

    /**
     * Divide el valor de C "452-ARI" en número (452) y signo (ARI).
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function splitTripleSigno(string $valor): array
    {
        if (preg_match('/^(\d+)-([A-Za-z]+)$/', trim($valor), $matches)) {
            return [$matches[1], strtoupper($matches[2])];
        }

        return [$valor, null];
    }
}
