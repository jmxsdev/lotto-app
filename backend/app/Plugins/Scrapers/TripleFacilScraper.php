<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;

/**
 * Scraper dedicado para Triple Fácil usando la API oficial de la plataforma
 * lotterly.co (la MISMA de Loto Chaima y Selva Plus, con `product_slug`
 * distinto). El sitio oficial triplefacil.com es una SPA que consume esta API.
 *
 * Endpoint público (sin auth ni anti-bot):
 *
 *   GET https://api.lotterly.co/v1/results/triple-facil/?exact_date=YYYY-MM-DD
 *
 * La respuesta es un array de sorteos (uno por horario del día, 12 en total,
 * 08:00–19:00 cada hora `:00`):
 *
 *   [{"date":"2026-09-11","time":"08:00:00","result":"489"},
 *    {"date":"2026-09-11","time":"09:00:00","result":"458"}, ...]
 *
 * Decisiones:
 * - El API ya filtra por fecha (`exact_date`): `execute` carga la fecha
 *   solicitada sin filtrar (patrón LaGranjita / LotoChaima).
 * - `time` viene en 24h `HH:MM:SS` y se normaliza a "H:i" con `normalizeHora`
 *   del BaseScraper ("08:00:00" → "08:00").
 * - `result` es un STRING de 3 cifras CON ceros a la izquierda ("073", "049").
 *   Se normaliza con padding a 3 dígitos por si viniera corto ("73"→"073",
 *   "7"→"007") y se guarda como STRING en `triple_a` (patrón Trio Activo /
 *   La Ricachona): `numeros_ganadores = {"pais":"VE","triple_a":"346"}`.
 * - El juego NO tiene signos ni animalitos: es un triple (000-999) + terminal
 *   derivado. La web muestra por sorteo `prev / main / next` donde `main` es
 *   el TRIPLE y `prev`/`next` son TERMINALES DERIVADAS (los 2 últimos dígitos
 *   ±1, calculados matemáticamente en el front: r = n % 100, prev = r-1,
 *   next = r+1). NO son resultados independientes: probados los slugs
 *   `triple-facil-terminal`, `terminal-facil`, `triple-facil-terminales` y
 *   `terminales-facil` en lotterly → 400 "product_slug does not exist". No
 *   existe juego/producto terminal aparte en la plataforma.
 * - Sin ID externo por sorteo → `sorteo_id_externo` null y dedupe por
 *   juego+fecha+hora en `saveResults` heredado.
 * - Sorteos sin `result` (null/ausente) se saltan (resultados parciales del
 *   día, patrón loteriadehoy modo animalitos).
 * - Respuesta vacía (string o array `[]`), JSON inválido o estructura
 *   inesperada → RuntimeException (fail-fast). `[]` ocurre legítimamente para
 *   fechas sin sorteos (p. ej. antes del lanzamiento).
 */
class TripleFacilScraper extends BaseScraper
{
    protected string $scraperName = 'TripleFacilScraper';

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
            throw new \RuntimeException('TripleFacilScraper requiere un juego con scraper_url; ejecuta su seeder');
        }

        // La scraper_url documenta el endpoint sin query. La query real se
        // construye aquí con la fecha solicitada, ignorando la query documental.
        $partes = parse_url($base);
        $url = ($partes['scheme'] ?? 'https').'://'.($partes['host'] ?? '').($partes['path'] ?? '');

        return $this->getJson($url.'?exact_date='.$fecha);
    }

    public function parse(string $rawData): array
    {
        $data = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar JSON: '.json_last_error_msg());
        }

        // Respuesta vacía (string vacío → null) o sin entradas (array []) no
        // son datos válidos.
        if (! is_array($data) || $data === []) {
            throw new \RuntimeException('Respuesta vacía o sin la estructura esperada del API de Triple Fácil');
        }

        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        $resultados = [];

        foreach ($data as $sorteo) {
            // Sorteo sin resultado (defensivo; el API solo devuelve ocurridos).
            if (! isset($sorteo['result']) || $sorteo['result'] === null || $sorteo['result'] === '') {
                continue;
            }

            $resultados[] = [
                'juego_id' => $juego->id,
                'hora_sorteo' => $this->normalizeHora($sorteo['time'] ?? null),
                'numeros_ganadores' => $this->numerosGanadores((string) $sorteo['result']),
                'sorteo_id_externo' => null,
                'premios_detalle' => null,
            ];
        }

        return $resultados;
    }

    /**
     * Construye `numeros_ganadores` desde el string del API.
     *
     * El triple viaja como STRING de 3 cifras (con ceros a la izquierda) y se
     * conserva como string con padding a 3 dígitos ("073", "489"): patrón
     * Trio Activo / La Ricachona (el plugin Tripletas valida `/^\d{3}$/`).
     *
     * @return array{pais: string, triple_a: string}
     */
    protected function numerosGanadores(string $resultado): array
    {
        return [
            'pais' => 'VE',
            'triple_a' => str_pad($resultado, 3, '0', STR_PAD_LEFT),
        ];
    }

    protected function getJson(string $url): string
    {
        $response = $this->client->get($url, [
            'headers' => ['Accept' => 'application/json'],
        ]);

        $body = (string) $response->getBody();
        $this->logInfo("JSON obtenido de {$url}, longitud: ".strlen($body));

        return $body;
    }
}
