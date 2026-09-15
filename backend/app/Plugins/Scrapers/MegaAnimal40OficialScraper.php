<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;

/**
 * Scraper OFICIAL de Mega Animal 40 (migración WU f27): reemplaza al agregador
 * resultadosvenezuela.com (MegaAnimal40Scraper, clase durmiente NO borrada —
 * ver backend/docs/juegos.md).
 *
 * Endpoint oficial (sin auth, sin anti-bot):
 *
 *   POST https://megaanimal40.com/core/process.php
 *   form-data: option=<token de resultados>
 *
 * Respuesta JSON:
 *
 *   {"msg":"Datos recopilados","status":true,"datos":[
 *     {"id":"1","name":"Mega Animal 40","image":"...","link":"mega_animal40","pais":"1","resultados":[
 *       {"date_result":"2026-09-14","number_animal":"09","animalito":"Águila",
 *        "color":"danger","time_s":"03:00 PM","mega":"1"}, ...]}]}
 *
 * Decisiones:
 * - `resultados[]` = sorteos del DÍA ACTUAL, ordenados de más reciente a más
 *   antiguo. `time_s` viene en 12h ("03:00 PM") → `normalizeHora` a "H:i".
 *   `number_animal` viene en 2 dígitos ("09") → int (9); `animalito` conserva
 *   los acentos ("Águila").
 * - COMODÍN MEGA: el campo `mega` documentado por el JS oficial del sitio —
 *   `if (b.mega == "2") { ...muestra la palabra MEGA... }` — se mapea a
 *   `numeros_ganadores["comodin"]`: "1" → false (sin comodín), "2" → true
 *   (SALIÓ EL COMODÍN MEGA, premio 40×). Resuelve H1/H20. La liquidación del
 *   comodín (premio 40×) pertenece al ciclo futuro del motor; aquí SOLO se
 *   captura el dato.
 * - LIMITACIÓN: el endpoint IGNORA los parámetros de fecha (probados
 *   fecha/date/dia → siempre devuelve el DÍA ACTUAL) y el sitio no expone
 *   histórico funcional (la página /historial/ usa el mismo token) → el
 *   scraper solo sirve el día actual. `execute` filtra por la fecha pedida
 *   (patrón TripleCalienteOficialScraper): si el endpoint devuelve otra fecha
 *   (siempre hoy), la fecha pedida produce `[]`. Los históricos previos en BD
 *   eran del proveedor y quedan.
 * - Defensivo: una entrada sin `date_result`, `time_s` o `number_animal` se
 *   salta; `mega` ausente se trata como sin comodín. Respuesta JSON inválida o
 *   `status !== true` → RuntimeException (fail-fast); respuesta VÁLIDA sin
 *   datos/resultados → `[]` (estado legítimo).
 * - Sin ID externo por sorteo → `sorteo_id_externo` null y dedupe por
 *   juego+fecha+hora en `saveResults` heredado.
 */
class MegaAnimal40OficialScraper extends BaseScraper
{
    protected string $scraperName = 'MegaAnimal40OficialScraper';

    protected const ENDPOINT_PATH = '/core/process.php';

    protected const RESULTADOS_TOKEN = 'w6BxxLHgvWgZ5DjzmKm_YPKEACoNWU0lMXKGmjymXApfEve5YDVS8pgM-BFPGP3a1I8NdjjyF-zkVFFk9u7gsn3Ri5iOHK6j19mjWGNxGut3UvgSY07TbzSOYQOJLnnZ';

    protected ?Juego $juego;

    public function __construct(?Juego $juego = null)
    {
        parent::__construct();
        $this->juego = $juego;
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

            $this->logInfo('Scrape completado. '.count($resultados)." resultados para {$fecha} (descartados ".(count($todosResultados) - count($resultados)).' de otras fechas; el endpoint solo sirve el día actual)');

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
        $base = (string) $this->juego?->scraper_url;

        if (! $base) {
            throw new \RuntimeException('MegaAnimal40OficialScraper requiere un juego con scraper_url; ejecuta su seeder');
        }

        $url = rtrim($base, '/').self::ENDPOINT_PATH;

        return $this->postJson($url, ['option' => self::RESULTADOS_TOKEN]);
    }

    public function parse(string $rawData): array
    {
        $data = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar JSON: '.json_last_error_msg());
        }

        if (! is_array($data) || ($data['status'] ?? null) !== true) {
            throw new \RuntimeException('Respuesta inválida del sitio oficial megaanimal40.com (status no true o sin estructura)');
        }

        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        $resultados = [];

        foreach ($data['datos'] ?? [] as $producto) {
            foreach ($producto['resultados'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $fecha = $item['date_result'] ?? null;
                $hora = $this->normalizeHora($item['time_s'] ?? null);
                $numero = $item['number_animal'] ?? null;

                // Entrada incompleta: se salta (defensivo ante cambios del API).
                if ($fecha === null || $hora === null || $numero === null || ! is_numeric($numero)) {
                    continue;
                }

                $resultados[] = [
                    'juego_id' => $juego->id,
                    'fecha_sorteo' => $fecha,
                    'hora_sorteo' => $hora,
                    'numeros_ganadores' => [
                        'pais' => 'VE',
                        'numero' => (int) $numero,
                        'nombre_animal' => (string) ($item['animalito'] ?? ''),
                        'comodin' => ($item['mega'] ?? '1') === '2',
                    ],
                    'sorteo_id_externo' => null,
                    'premios_detalle' => null,
                ];
            }
        }

        return $resultados;
    }

    /**
     * @param  array<int, array<string, mixed>>  $resultados
     * @return array<int, array<string, mixed>>
     */
    protected function filtrarPorFecha(array $resultados, string $fecha): array
    {
        return array_values(array_filter(
            $resultados,
            fn (array $resultado) => ($resultado['fecha_sorteo'] ?? null) === $fecha
        ));
    }
}
