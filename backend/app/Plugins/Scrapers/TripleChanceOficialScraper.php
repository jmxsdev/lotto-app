<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use Illuminate\Support\Carbon;

/**
 * Scraper dedicado para Triple Chance usando la API oficial de
 * tuchance.com.ve ("Chance en línea"), un WordPress que consume
 * api.scalalot.com.
 *
 * Endpoint público (sin auth ni anti-bot):
 *
 *   GET https://api.scalalot.com/servicelotteryresults/ServicioResultados.svc/
 *       ServicioResultados/ConsultarResultadoSorteo/Q0hBTkNF/{timestamp}
 *
 * (Q0hBTkNF = base64("CHANCE"); timestamp = epoch del día en America/Caracas,
 * como lo calcula el front oficial: `Math.floor(new Date('MM/DD/YYYY').getTime()/1000)`).
 *
 * La respuesta es un objeto con `datos` (un registro por modalidad y horario)
 * y `mensaje`:
 *
 *   {"datos":[{"codProducto":"CHANCE","desSorteo":"CHANCE AYB 09:00 AM",
 *    "horSorteo":"09:00 AM","numA":"NDkyICAg","numB":"MTE0ICAg","simA":"","simB":""}, ...],
 *    "mensaje":{"codigo":"000","descripcion":"Respuesta Exitosa."}}
 *
 * Decisiones:
 * - El juego `triple-chance` es de tipo TRIPLETAS (A/B/C + signo zodiacal) y
 *   consume SOLO dos de las tres modalidades por horario:
 *     • CHANCE AYB    → Triple A (`numA`) + Triple B (`numB`)
 *     • CHANCE ASTRAL → Triple C (`numA`) + signo (`simA`)
 *   CHANCE ANIMALITO (2 animalitos) pertenece a otro juego (chance-animalitos,
 *   candidato en `docs/plataformas-juegos.md`) y se ignora aquí.
 * - Los campos `numA`/`numB` vienen en base64 de un string con padding
 *   ("492   ") → se decodifica y recorta. `simA` en base64 del signo en
 *   nombre completo ("VIRGO", "SAGITARIO") → se mapea a la sigla de 3 letras
 *   (VIR, SAG) que usan las opciones del juego y los demás triples con signo.
 * - `horSorteo` en 12h ("09:00 AM") → `normalizeHora` a "H:i".
 * - Se genera UN resultado por horario (fusión AYB + ASTRAL); un horario sin
 *   AYB se omite (el sorteo no está completo).
 * - Sin ID externo por sorteo → `sorteo_id_externo` null y dedupe por
 *   juego+fecha+hora en `saveResults` heredado.
 * - Respuesta vacía, sin `datos` (fecha sin sorteos: mensaje codigo 012),
 *   JSON inválido o estructura inesperada → RuntimeException (fail-fast).
 * - Signo desconocido (no en el mapa oficial de 12) se conserva tal cual
 *   (defensivo, sin mapeos inventados — patrón SelvaPlus).
 */
class TripleChanceOficialScraper extends BaseScraper
{
    protected string $scraperName = 'TripleChanceOficialScraper';

    /**
     * Mapa signo zodiacal (nombre completo como viaja en la API) → sigla de 3
     * letras (formato de las opciones del juego y de los triples con signo).
     *
     * @var array<string, string>
     */
    public const SIGNOS = [
        'ARIES' => 'ARI',
        'TAURO' => 'TAU',
        'GEMINIS' => 'GEM',
        'CANCER' => 'CAN',
        'LEO' => 'LEO',
        'VIRGO' => 'VIR',
        'LIBRA' => 'LIB',
        'ESCORPIO' => 'ESC',
        'SAGITARIO' => 'SAG',
        'CAPRICORNIO' => 'CAP',
        'ACUARIO' => 'ACU',
        'PISCIS' => 'PIS',
    ];

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
            throw new \RuntimeException('TripleChanceOficialScraper requiere un juego con scraper_url; ejecuta su seeder');
        }

        // El front oficial calcula el timestamp como la medianoche local
        // (America/Caracas) del día solicitado: `new Date('MM/DD/YYYY').getTime()/1000`.
        $timestamp = Carbon::parse($fecha, 'America/Caracas')->startOfDay()->getTimestamp();

        return $this->getJson(rtrim($base, '/').'/'.$timestamp);
    }

    public function parse(string $rawData): array
    {
        $data = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error al decodificar JSON: '.json_last_error_msg());
        }

        // Respuesta vacía, sin `datos` (fecha sin sorteos) o sin la estructura
        // esperada → no son datos válidos.
        if (! is_array($data) || ! isset($data['datos']) || ! is_array($data['datos']) || $data['datos'] === []) {
            throw new \RuntimeException('Respuesta vacía o sin la estructura esperada del API de Triple Chance');
        }

        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        // Agrupa por horario las modalidades AYB y ASTRAL; ANIMALITO se ignora.
        $porHorario = [];

        foreach ($data['datos'] as $sorteo) {
            $desSorteo = (string) ($sorteo['desSorteo'] ?? '');

            if (str_contains($desSorteo, 'ANIMALITO')) {
                continue;
            }

            $hora = $this->normalizeHora($sorteo['horSorteo'] ?? null);
            if (! $hora) {
                continue;
            }

            $porHorario[$hora] ??= [];

            if (str_contains($desSorteo, 'ASTRAL')) {
                $porHorario[$hora]['triple_c'] = $this->decodificarNumero($sorteo['numA'] ?? null);
                $porHorario[$hora]['signo'] = $this->mapSigno($this->decodificarTexto($sorteo['simA'] ?? null));
            } else {
                // CHANCE AYB (Triple A + Triple B)
                $porHorario[$hora]['triple_a'] = $this->decodificarNumero($sorteo['numA'] ?? null);
                $porHorario[$hora]['triple_b'] = $this->decodificarNumero($sorteo['numB'] ?? null);
            }
        }

        $resultados = [];

        foreach ($porHorario as $hora => $datos) {
            // Un sorteo completo requiere al menos el AYB (el par A/B); sin él
            // el horario no está completo y se omite.
            if (empty($datos['triple_a']) && empty($datos['triple_b'])) {
                continue;
            }

            $resultados[] = [
                'juego_id' => $juego->id,
                'hora_sorteo' => $hora,
                'numeros_ganadores' => [
                    'pais' => 'VE',
                    'triple_a' => $datos['triple_a'] ?? null,
                    'triple_b' => $datos['triple_b'] ?? null,
                    'triple_c' => $datos['triple_c'] ?? null,
                    'signo' => $datos['signo'] ?? null,
                ],
                'sorteo_id_externo' => null,
                'premios_detalle' => null,
            ];
        }

        // El API devuelve los horarios en el orden del día (13:00 antes que
        // 09:00); se ordenan ascendentemente para salida determinista.
        usort($resultados, fn (array $a, array $b) => $a['hora_sorteo'] <=> $b['hora_sorteo']);

        return $resultados;
    }

    /**
     * Decodifica un número en base64 con padding ("NDkyICAg" → "492   " → "492").
     */
    protected function decodificarNumero(?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $decodificado = trim(base64_decode($valor, true) ?: '');

        return $decodificado === '' ? null : $decodificado;
    }

    /**
     * Decodifica un texto en base64 ("VklSR08=" → "VIRGO").
     */
    protected function decodificarTexto(?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $decodificado = trim(base64_decode($valor, true) ?: '');

        return $decodificado === '' ? null : $decodificado;
    }

    /**
     * Mapea el nombre completo del signo a la sigla de 3 letras. Si el signo no
     * está en el mapa oficial (defensivo), se conserva tal cual.
     */
    protected function mapSigno(?string $signo): ?string
    {
        if ($signo === null) {
            return null;
        }

        return self::SIGNOS[$signo] ?? $signo;
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
