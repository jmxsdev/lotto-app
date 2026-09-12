<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;

/**
 * Scraper dedicado para La Granjita usando la API oficial de lagranjita.com.
 *
 * Endpoint público (sin auth ni anti-bot):
 *
 *   GET https://www.lagranjita.com/api/results.json?date=YYYY-MM-DD&productId=1
 *
 * La respuesta es un objeto con el nombre del producto como clave y un array
 * de sorteos como valor (uno por horario del día, 12 en total):
 *
 *   {"LA GRANJITA":[{"result_id":414878,"result_name":"GALLINA",
 *   "result_value":"25","lotery_hour":"08:00 AM", ...}, ...]}
 *
 * Decisiones:
 * - `product_id`: constante `PRODUCT_ID` ('1') como default, con override vía
 *   `config['scraper']['product_id']` del juego registrado (convención D6 del
 *   diseño: la clase lee la config del juego; el seeder la registra).
 * - La clave del objeto es el nombre del producto ("LA GRANJITA"): se toma el
 *   PRIMER valor del objeto, sin hardcodear la clave.
 * - Sorteos NO ocurridos: el API los incluye con `result_id: null` y el resto
 *   de campos null → se saltan (resultados parciales del día, patrón
 *   loteriadehoy modo animalitos).
 * - `result_id` es único por sorteo → `sorteo_id_externo` (dedupe por
 *   juego+fecha+hora en `saveResults` heredado).
 * - `result_value` = número del animal ("25"), `result_name` = nombre del
 *   animal ("GALLINA"); `lotery_hour` en 12h ("08:00 AM") se normaliza a "H:i"
 *   con `normalizeHora` del BaseScraper.
 * - El API soporta fechas: `execute` carga la fecha solicitada sin filtrar
 *   (a diferencia del histórico de Triple Caliente, aquí no hay que filtrar).
 */
class LaGranjitaScraper extends BaseScraper
{
    protected string $scraperName = 'LaGranjitaScraper';

    protected const PRODUCT_ID = '1';

    protected ?Juego $juego;

    protected string $productId;

    public function __construct(?Juego $juego = null)
    {
        parent::__construct();
        $this->juego = $juego;
        $this->productId = (string) ($juego?->config['scraper']['product_id'] ?? self::PRODUCT_ID);
    }

    public function fetch(string $fecha): string
    {
        $base = (string) $this->juego?->scraper_url;

        if (! $base) {
            throw new \RuntimeException('LaGranjitaScraper requiere un juego con scraper_url; ejecuta su seeder');
        }

        // La scraper_url documenta el endpoint con el productId por defecto
        // (https://www.lagranjita.com/api/results.json?productId=1). La query
        // real se construye aquí con la fecha solicitada y el productId de
        // config, ignorando la query documental.
        $partes = parse_url($base);
        $url = ($partes['scheme'] ?? 'https').'://'.($partes['host'] ?? '').($partes['path'] ?? '');

        $query = http_build_query([
            'date' => $fecha,
            'productId' => $this->productId,
        ]);

        return $this->getJson($url.'?'.$query);
    }

    public function parse(string $rawData): array
    {
        $data = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar JSON: '.json_last_error_msg());
        }

        // Respuesta vacía (string vacío → null) o sin el array de sorteos
        // (objeto vacío o forma inesperada) no son datos válidos.
        if (! is_array($data) || $data === []) {
            throw new \RuntimeException('Respuesta vacía o sin la estructura esperada del API de La Granjita');
        }

        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        // La clave del objeto es el nombre del producto; se toma el primer valor.
        $sorteos = reset($data);

        if (! is_array($sorteos)) {
            throw new \RuntimeException('Estructura inesperada del API de La Granjita: el valor del producto no es un array');
        }

        $resultados = [];

        foreach ($sorteos as $sorteo) {
            // Sorteo no ocurrido: result_id null (y resto de campos null).
            if (empty($sorteo['result_id'])) {
                continue;
            }

            $resultados[] = [
                'juego_id' => $juego->id,
                'hora_sorteo' => $this->normalizeHora($sorteo['lotery_hour'] ?? null),
                'numeros_ganadores' => [
                    'numero' => (int) ($sorteo['result_value'] ?? null),
                    'nombre_animal' => $sorteo['result_name'] ?? null,
                    'pais' => 'VE',
                ],
                'sorteo_id_externo' => (string) $sorteo['result_id'],
                'premios_detalle' => null,
            ];
        }

        return $resultados;
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
