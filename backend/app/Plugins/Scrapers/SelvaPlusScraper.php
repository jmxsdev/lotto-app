<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;

/**
 * Scraper dedicado para Selva Plus usando la API oficial de la plataforma
 * lotterly.co (la MISMA de Loto Chaima, con `product_slug` distinto).
 *
 * Endpoint público (sin auth ni anti-bot):
 *
 *   GET https://api.lotterly.co/v1/results/selva-plus/?exact_date=YYYY-MM-DD
 *
 * La respuesta es un array de sorteos (uno por horario del día, 13 en total,
 * 08:15–20:15 cada hora `:15`):
 *
 *   [{"date":"2026-09-11","time":"08:15:00","result":"27"},
 *    {"date":"2026-09-11","time":"09:15:00","result":"11"}, ...]
 *
 * Decisiones:
 * - El API ya filtra por fecha (`exact_date`): `execute` carga la fecha
 *   solicitada sin filtrar (patrón LaGranjita / LotoChaima).
 * - `time` viene en 24h `HH:MM:SS` y se normaliza a "H:i" con `normalizeHora`
 *   del BaseScraper ("08:15:00" → "08:15").
 * - `result` es un STRING numérico 00-99 con padding de 2 dígitos salvo el
 *   cero: "0", "04", "87"... El sitio resuelve la figura con el string tal
 *   cual ("0"→Delfín, "04"→Alacrán). Por robustez el scraper maneja además el
 *   caso "00" (Ballena) y el fallback de padding ("8"→"08"→Ratón).
 * - El zoológico es PROPIO de **101 figuras (0–99, Ballena y Delfín comparten
 *   el 0)**, distinto al canónico del plugin Animalitos. El mapa es la fuente
 *   de los nombres y de las opciones del juego (el seeder lo comparte como
 *   `ZOOLOGICO`, patrón Loto Chaima).
 * - COMODINES (2): Comodín A "Leoncito" (160×) y Comodín B "Selva Plus"
 *   (200×). Su representación en `result` NO se ha observado aún (65 sorteos
 *   del 07-11 sep, todos numéricos) → parser DEFENSIVO: si `result` no es
 *   numérico, se guarda el valor crudo en `numeros_ganadores` (clave
 *   `resultado_crudo`) + log de advertencia. NO se inventan mapeos.
 * - Sin ID externo por sorteo → `sorteo_id_externo` null y dedupe por
 *   juego+fecha+hora en `saveResults` heredado.
 * - Sorteos sin `result` (null/ausente) se saltan (resultados parciales del
 *   día, patrón loteriadehoy modo animalitos).
 * - Respuesta vacía (string o array `[]`), JSON inválido o estructura
 *   inesperada → RuntimeException (fail-fast). `[]` ocurre legítimamente para
 *   fechas anteriores al lanzamiento del juego (2026-09-07).
 */
class SelvaPlusScraper extends BaseScraper
{
    protected string $scraperName = 'SelvaPlusScraper';

    /**
     * Zoológico propio de Selva Plus (101 figuras, 0–99) extraído del bundle
     * oficial del sitio. Claves tal como viajan en el API (con y sin padding):
     * "00" → Ballena, "0" → Delfín, "01".."99" con padding de 2 dígitos.
     * El orden del array define el `sort_order` de las opciones del juego.
     *
     * @var array<string, string>
     */
    public const ZOOLOGICO = [
        '00' => 'Ballena',
        '0' => 'Delfín',
        '01' => 'Carnero',
        '02' => 'Toro',
        '03' => 'Ciempiés',
        '04' => 'Alacrán',
        '05' => 'León',
        '06' => 'Rana',
        '07' => 'Perico',
        '08' => 'Ratón',
        '09' => 'Águila',
        '10' => 'Tigre',
        '11' => 'Gato',
        '12' => 'Caballo',
        '13' => 'Mono',
        '14' => 'Paloma',
        '15' => 'Zorro',
        '16' => 'Oso',
        '17' => 'Pavo',
        '18' => 'Burro',
        '19' => 'Chivo',
        '20' => 'Cochino',
        '21' => 'Gallo',
        '22' => 'Camello',
        '23' => 'Cebra',
        '24' => 'Iguana',
        '25' => 'Gallina',
        '26' => 'Vaca',
        '27' => 'Perro',
        '28' => 'Zamuro',
        '29' => 'Elefante',
        '30' => 'Caimán',
        '31' => 'Lapa',
        '32' => 'Ardilla',
        '33' => 'Pescado',
        '34' => 'Venado',
        '35' => 'Jirafa',
        '36' => 'Culebra',
        '37' => 'Tortuga',
        '38' => 'Búfalo',
        '39' => 'Lechuza',
        '40' => 'Avispa',
        '41' => 'Canguro',
        '42' => 'Tucán',
        '43' => 'Mariposa',
        '44' => 'Chigüire',
        '45' => 'Garza',
        '46' => 'Puma',
        '47' => 'Pavo Real',
        '48' => 'Puercoespín',
        '49' => 'Pereza',
        '50' => 'Canario',
        '51' => 'Pelícano',
        '52' => 'Pulpo',
        '53' => 'Caracol',
        '54' => 'Grillo',
        '55' => 'Oso Hormiguero',
        '56' => 'Tiburón',
        '57' => 'Pato',
        '58' => 'Hormiga',
        '59' => 'Pantera',
        '60' => 'Camaleón',
        '61' => 'Panda',
        '62' => 'Cachicamo',
        '63' => 'Cangrejo',
        '64' => 'Gavilán',
        '65' => 'Araña',
        '66' => 'Lobo',
        '67' => 'Avestruz',
        '68' => 'Jaguar',
        '69' => 'Conejo',
        '70' => 'Bisonte',
        '71' => 'Guacamaya',
        '72' => 'Gorila',
        '73' => 'Hipopótamo',
        '74' => 'Turpial',
        '75' => 'Guácharo',
        '76' => 'Rinoceronte',
        '77' => 'Pingüino',
        '78' => 'Antílope',
        '79' => 'Calamar',
        '80' => 'Murciélago',
        '81' => 'Cuervo',
        '82' => 'Cucaracha',
        '83' => 'Búho',
        '84' => 'Camarón',
        '85' => 'Hámster',
        '86' => 'Buey',
        '87' => 'Cabra',
        '88' => 'Erizo de Mar',
        '89' => 'Anguila',
        '90' => 'Hurón',
        '91' => 'Morrocoy',
        '92' => 'Cisne',
        '93' => 'Gaviota',
        '94' => 'Paujil',
        '95' => 'Escarabajo',
        '96' => 'Caballito de Mar',
        '97' => 'Loro',
        '98' => 'Cocodrilo',
        '99' => 'Halcón',
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
            throw new \RuntimeException('SelvaPlusScraper requiere un juego con scraper_url; ejecuta su seeder');
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
            throw new \RuntimeException('Respuesta vacía o sin la estructura esperada del API de Selva Plus');
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
     * Caso normal (numérico 00-99): `{pais, numero, nombre_animal}`.
     * Caso DEFENSIVO (no numérico — posible representación de un comodín, NO
     * observada aún): `{pais, resultado_crudo}` + log de advertencia. Nunca se
     * inventa un mapeo para valores desconocidos.
     *
     * @return array{pais: string, numero?: int, nombre_animal?: string, resultado_crudo?: string}
     */
    protected function numerosGanadores(string $resultado): array
    {
        $base = ['pais' => 'VE'];

        if (! is_numeric($resultado)) {
            $this->logWarning("result no numérico: '{$resultado}' — guardando valor crudo (¿comodín?)", [
                'juego' => $this->juego?->slug,
            ]);

            return $base + ['resultado_crudo' => $resultado];
        }

        return $base + [
            'numero' => (int) $resultado,
            'nombre_animal' => $this->nombreAnimal($resultado),
        ];
    }

    /**
     * Resuelve el nombre de la figura por el mapa del zoológico propio.
     * Primero intenta con el string tal cual llega del API ("0"→Delfín,
     * "04"→Alacrán, "00"→Ballena); si no está, reintenta con el padding a 2
     * dígitos ("8"→"08"→Ratón). Devuelve null si el número no está en el mapa.
     */
    protected function nombreAnimal(string $resultado): ?string
    {
        $nombre = self::ZOOLOGICO[$resultado] ?? null;

        if ($nombre !== null) {
            return $nombre;
        }

        $pad = str_pad((string) ((int) $resultado), 2, '0', STR_PAD_LEFT);

        return self::ZOOLOGICO[$pad] ?? null;
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
