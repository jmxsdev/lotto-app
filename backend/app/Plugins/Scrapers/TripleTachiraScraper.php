<?php

namespace App\Plugins\Scrapers;

use App\Models\Juego;
use Illuminate\Support\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper dedicado para Triple Táchira usando el sitio oficial
 * tripletachira.com (sin API JSON pública; HTML server-rendered).
 *
 * Endpoint (verificado el 12-sep-2026, sin anti-bot):
 *
 *   GET https://tripletachira.com/pruebah.php?bt=DD/MM/YYYY&bt2=DD/MM/YYYY
 *
 * Devuelve un fragmento HTML con UNA tabla semanal: 7 columnas desde `bt`
 * (headers tipo `<th scope="col">Lunes<br>11/09/2026</th>`) y filas por
 * horario/modalidad (la hora en el `<th>` de la fila):
 *
 *   <tr><th>01:15&nbsp;A</th><td>245</td>...</tr>
 *   <tr><th>01:15&nbsp;B</th><td>998</td>...</tr>
 *   <tr><th style="background: darkorange;">01:15 <br>ZODI</th>
 *       <td>160 <br>PIC.</td>...</tr>
 *   ... 04:45 A/B/ZODI ... 10:10 A/B/ZODI ...
 *
 * Decisiones (verificadas contra datos reales y el reglamento oficial):
 * - Estrategia: pedir SIEMPRE `bt=fecha&bt2=fecha` (la primera columna es la
 *   fecha pedida) y ADEMÁS localizar la columna por su FECHA en el header
 *   (robusto a cambios). El nombre del día del header es FIJO (Lunes..Domingo),
 *   NO el día real de la fecha (01-09-2026 es martes y el sitio lo etiqueta
 *   "Lunes") → NUNCA se usa el nombre del día para ubicar la columna.
 * - Horas: vienen en formato 12h SIN sufijo AM/PM ("01:15", "04:45", "10:10")
 *   y TODOS los sorteos son PM (la home etiqueta "1:15PM"; el reglamento
 *   oficial G-20004065-3 lista 1:15/4:45/10:10) → `horaTablaA24h` convierte a
 *   24h: 01:15→13:15, 04:45→16:45, 10:10→22:10 (h<12 → +12; 12 → 12).
 * - Celdas `--------` = sin sorteo → se saltan; una fecha cuya columna está
 *   toda `--------` devuelve `[]` (estado VÁLIDO: el sitio renderiza la tabla
 *   completa para fechas sin datos, p. ej. 2020-01-01; los domingos suelen
 *   tener menos sorteos — 06-sep solo 22:10, 13-sep ninguno — ver
 *   docs/comparacion-juegos.md H9). Solo un cuerpo vacío o un HTML sin la
 *   tabla esperada es un error (RuntimeException, fail-fast).
 * - ZODI: la celda trae el triple + signo de 3 letras + punto ("160 <br>PIC.")
 *   → `triple_c` + `signo` mapeado con `mapearSigno` (PIC→PIS, resto igual;
 *   sigla desconocida se conserva sin punto + log de advertencia, defensivo).
 * - A/B: número de 3 cifras como STRING (cero inicial conservado, p. ej. "033").
 * - Sin ID externo por sorteo → `sorteo_id_externo` null y dedupe por
 *   juego+fecha+hora en `saveResults` heredado.
 */
class TripleTachiraScraper extends BaseScraper
{
    protected string $scraperName = 'TripleTachiraScraper';

    /**
     * Signos del sitio (3 letras + punto) → códigos del esquema tripletas.
     * La única diferencia es PIC → PIS (Piscis); el resto coincide.
     *
     * @var array<string, string>
     */
    public const SIGNOS = [
        'ARI' => 'ARI',
        'TAU' => 'TAU',
        'GEM' => 'GEM',
        'CAN' => 'CAN',
        'LEO' => 'LEO',
        'VIR' => 'VIR',
        'LIB' => 'LIB',
        'ESC' => 'ESC',
        'SAG' => 'SAG',
        'CAP' => 'CAP',
        'ACU' => 'ACU',
        'PIC' => 'PIS',
    ];

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
            $resultados = $this->parse($rawData, $fecha);

            $this->logInfo('Scrape completado. Resultados obtenidos: '.count($resultados));

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
            throw new \RuntimeException('TripleTachiraScraper requiere un juego con scraper_url; ejecuta su seeder');
        }

        // La scraper_url documenta pruebah.php sin query. La query real
        // (bt/bt2 = la fecha pedida, formato DD/MM/YYYY) se construye aquí,
        // ignorando cualquier query documental.
        $partes = parse_url($base);
        $url = ($partes['scheme'] ?? 'https').'://'.($partes['host'] ?? '').($partes['path'] ?? '');

        $dmy = Carbon::parse($fecha, 'America/Caracas')->format('d/m/Y');

        return $this->getHtml($url.'?bt='.$dmy.'&bt2='.$dmy);
    }

    public function parse(string $rawData, ?string $fechaObjetivo = null): array
    {
        // Cuerpo vacío/inexistente: respuesta inválida (fail-fast). Una página
        // VÁLIDA con la tabla (aunque todas las celdas sean `--------`) es un
        // estado válido → devuelve [] más abajo.
        if (trim($rawData) === '') {
            throw new \RuntimeException('Respuesta vacía del proveedor tripletachira.com');
        }

        $crawler = $this->createCrawler($rawData);
        $tabla = $crawler->filter('#main-table');

        if ($tabla->count() === 0) {
            throw new \RuntimeException('HTML sin la tabla de resultados esperada (#main-table)');
        }

        // Localizar la columna por su FECHA en el header (nunca por el nombre
        // del día: el sitio lo etiqueta fijo Lunes..Domingo sin el día real).
        $fechasPorIndice = $this->fechasDelHeader($tabla);

        if ($fechasPorIndice === []) {
            throw new \RuntimeException('Tabla sin columnas de fecha en el header');
        }

        if ($fechaObjetivo === null) {
            // Sin fecha: usar la primera columna (la de `bt`).
            $indiceColumna = array_key_first($fechasPorIndice);
            $fechaEncontrada = $fechasPorIndice[$indiceColumna];
        } else {
            $buscada = Carbon::parse($fechaObjetivo, 'America/Caracas')->format('d/m/Y');
            $indiceColumna = null;

            foreach ($fechasPorIndice as $indice => $fechaHeader) {
                if ($fechaHeader === $buscada) {
                    $indiceColumna = $indice;
                    $fechaEncontrada = $fechaHeader;
                    break;
                }
            }

            if ($indiceColumna === null) {
                throw new \RuntimeException("Fecha {$buscada} no encontrada en el header de la tabla de Triple Táchira");
            }
        }

        $juego = $this->findJuegoOrFail([
            'slug' => $this->juego?->slug,
            'name' => $this->juego?->name,
        ]);

        // Agrupar A/B/ZODI por hora normalizada: cada sorteo es UN resultado
        // con las modalidades presentes (la tabla separa las filas por modalidad).
        // El índice del th del header es 1-based para las fechas (el 0 es
        // "Hora"); las celdas td de las filas van 0..6 → se resta 1.
        $indiceCelda = $indiceColumna - 1;
        $porHora = [];

        $tabla->filter('tbody tr')->each(function (Crawler $fila) use (&$porHora, $indiceCelda): void {
            $headerFila = $fila->filter('th');
            $celdas = $fila->filter('td');

            if ($headerFila->count() === 0 || $celdas->count() <= $indiceCelda) {
                return;
            }

            $horaTexto = $this->textoPlano($headerFila->text());
            if (! preg_match('/^(\d{1,2}):(\d{2})\s*([A-Z]+)$/', $horaTexto, $m)) {
                return;
            }

            $hora = $this->horaTablaA24h($m[1].':'.$m[2]);
            $modalidad = $m[3];

            if ($hora === null) {
                return;
            }

            $celda = $this->textoPlano($celdas->eq($indiceCelda)->text());

            if (str_contains($celda, '--------')) {
                return;
            }

            $porHora[$hora] ??= ['juego_id' => null, 'hora_sorteo' => $hora];

            if ($modalidad === 'A') {
                if (preg_match('/^(\d+)$/', $celda, $mA)) {
                    $porHora[$hora]['numeros_ganadores']['triple_a'] = $mA[1];
                }
            } elseif ($modalidad === 'B') {
                if (preg_match('/^(\d+)$/', $celda, $mB)) {
                    $porHora[$hora]['numeros_ganadores']['triple_b'] = $mB[1];
                }
            } elseif ($modalidad === 'ZODI') {
                if (preg_match('/^(\d+)\s+([A-Z]{3})\.?$/', $celda, $mZ)) {
                    $porHora[$hora]['numeros_ganadores']['triple_c'] = $mZ[1];
                    $porHora[$hora]['numeros_ganadores']['signo'] = $this->mapearSigno($mZ[2]);
                }
            }
        });

        $resultados = [];

        foreach ($porHora as $hora => $datos) {
            // Solo se persiste una hora si al menos una modalidad trajo valor.
            if (! isset($datos['numeros_ganadores'])) {
                continue;
            }

            $numeros = $datos['numeros_ganadores'];
            $numeros['pais'] = 'VE';

            $resultados[] = [
                'juego_id' => $juego->id,
                'hora_sorteo' => $hora,
                'numeros_ganadores' => $numeros,
                'sorteo_id_externo' => null,
                'premios_detalle' => null,
            ];
        }

        // Orden estable por hora (el orden del documento ya es cronológico).
        ksort($resultados, SORT_STRING);

        return array_values($resultados);
    }

    /**
     * Convierte la hora del sitio (12h, SIN sufijo AM/PM) a 24h "H:i".
     * Todos los sorteos del sitio son PM (verificado: home "1:15PM" y
     * reglamento oficial 1:15/4:45/10:10) → h<12 suma 12, 12 se mantiene.
     * Devuelve null si la hora es inválida.
     */
    public function horaTablaA24h(string $hora): ?string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($hora), $m)) {
            return null;
        }

        $h = (int) $m[1];
        $min = (int) $m[2];

        if ($h < 1 || $h > 12 || $min > 59) {
            return null;
        }

        $h24 = ($h === 12) ? 12 : $h + 12;

        return sprintf('%02d:%02d', $h24, $min);
    }

    /**
     * Mapea la sigla del sitio (3 letras, con punto en la celda) al código
     * del esquema tripletas. PIC → PIS (Piscis); el resto coincide. Una sigla
     * desconocida se conserva sin punto (defensivo, con log de advertencia).
     */
    public function mapearSigno(string $sigla): string
    {
        $sigla = strtoupper(trim($sigla, " \t\n\r\0\x0B."));

        if (isset(self::SIGNOS[$sigla])) {
            return self::SIGNOS[$sigla];
        }

        $this->logWarning("Signo zodiacal desconocido en el sitio: '{$sigla}' — conservado sin mapear", [
            'juego' => $this->juego?->slug,
        ]);

        return $sigla;
    }

    /**
     * @return array<int, string> índice del th del header → fecha "DD/MM/YYYY"
     */
    protected function fechasDelHeader(Crawler $tabla): array
    {
        $fechas = [];

        $tabla->filter('thead th')->each(function (Crawler $th, int $indice) use (&$fechas): void {
            if (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $th->text(), $m)) {
                $fechas[$indice] = $m[1];
            }
        });

        return $fechas;
    }

    /**
     * Normaliza el texto de un nodo: NBSP y espacios múltiples → un espacio.
     */
    protected function textoPlano(string $texto): string
    {
        return trim(preg_replace('/[\x{00A0}\s]+/u', ' ', $texto) ?? $texto);
    }
}
