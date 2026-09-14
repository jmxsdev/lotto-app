<?php

namespace Tests\Feature;

use App\Services\JuegoCatalogoService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JuegosJsonTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{version: int, juegos: array<int, array<string, mixed>>} */
    private array $archivo;

    /** @var array{version: int, juegos: array<int, array<string, mixed>>} */
    private array $generado;

    /**
     * Orden de siembra del DatabaseSeeder: los ids 1..15 deben coincidir
     * con el archivo commiteado (contrato estable para el front/taquilla).
     *
     * @var array<int, string>
     */
    private const SLUGS_POR_ID = [
        1 => 'lotto-activo',
        2 => 'triple-zulia',
        3 => 'terminal-activo',
        4 => 'lotto-activo-rd',
        5 => 'lotto-activo-rep-dom',
        6 => 'monje-millonario',
        7 => 'trio-activo',
        8 => 'triple-caliente',
        9 => 'cazaloton',
        10 => 'triple-chance',
        11 => 'el-arrejuntado',
        12 => 'el-guacharito',
        13 => 'guacharo-activo',
        14 => 'la-granjita',
        15 => 'la-ricachona',
        16 => 'loto-chaima',
        17 => 'mega-animal-40',
        18 => 'selva-plus',
        19 => 'triple-tachira',
        20 => 'triple-facil',
        21 => 'triple-zamorano',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->archivo = $this->leerArchivoCommiteado();
        $this->generado = app(JuegoCatalogoService::class)->generar();
    }

    /**
     * @return array{version: int, juegos: array<int, array<string, mixed>>}
     */
    private function leerArchivoCommiteado(): array
    {
        $ruta = base_path('../docs/juegos.json');

        $this->assertFileExists($ruta, 'docs/juegos.json debe existir commiteado (entregable para el front).');

        $contenido = file_get_contents($ruta);
        $this->assertNotFalse($contenido, 'No se pudo leer docs/juegos.json.');

        $datos = json_decode($contenido, true);
        $this->assertIsArray($datos, 'docs/juegos.json no es JSON válido.');

        return $datos;
    }

    public function test_el_catalogo_generado_coincide_con_el_archivo_commiteado(): void
    {
        $this->assertSame($this->archivo['version'], $this->generado['version']);

        $porSlugArchivo = collect($this->archivo['juegos'])->keyBy('slug');
        $porSlugGenerado = collect($this->generado['juegos'])->keyBy('slug');

        $this->assertEqualsCanonicalizing(
            $porSlugArchivo->keys()->all(),
            $porSlugGenerado->keys()->all(),
            'Los slugs del archivo commiteado y del catálogo generado deben coincidir.'
        );

        foreach ($porSlugArchivo as $slug => $juegoArchivo) {
            // La comparación es estable por slug y valores, NO por id: la BD de
            // tests reutiliza auto-increment de MySQL que no retrocede con el
            // rollback de RefreshDatabase (evidencia: ids corridos 27-39 en la
            // suite compartida), mientras que el archivo commiteado mantiene el
            // contrato estable con los ids reales 1-14 de la BD local.
            $juegoArchivoSinId = $juegoArchivo;
            $juegoGeneradoSinId = $porSlugGenerado[$slug];
            unset($juegoArchivoSinId['id'], $juegoGeneradoSinId['id']);

            $this->assertEquals(
                $juegoArchivoSinId,
                $juegoGeneradoSinId,
                "El juego [{$slug}] del archivo commiteado difiere del generado con la BD sembrada."
            );
        }
    }

    public function test_esquema_minimo_y_conteos_de_opciones_por_tipo(): void
    {
        $this->assertSame(1, $this->generado['version']);
        $this->assertCount(21, $this->generado['juegos']);

        $porSlug = collect($this->generado['juegos'])->keyBy('slug');

        foreach ($this->generado['juegos'] as $juego) {
            foreach (['id', 'slug', 'nombre', 'tipo', 'premio_multiplo', 'horarios', 'opciones'] as $campo) {
                $this->assertArrayHasKey($campo, $juego, "Falta el campo [{$campo}] en el juego [{$juego['slug']}].");
            }

            $this->assertNotEmpty($juego['horarios'], "El juego [{$juego['slug']}] no tiene horarios.");

            foreach ($juego['horarios'] as $hora) {
                $this->assertMatchesRegularExpression(
                    '/^\d{2}:\d{2}$/',
                    $hora,
                    "La hora [{$hora}] de [{$juego['slug']}] no está normalizada a H:i."
                );
            }

            $horariosOrdenados = $juego['horarios'];
            sort($horariosOrdenados);
            $this->assertSame(
                $horariosOrdenados,
                $juego['horarios'],
                "Los horarios de [{$juego['slug']}] no están ordenados ascendentemente."
            );
        }

        // premio_multiplo desde config del juego
        $this->assertSame(30, $porSlug['lotto-activo']['premio_multiplo']);
        $this->assertSame(60, $porSlug['terminal-activo']['premio_multiplo']);
        $this->assertSame(600, $porSlug['trio-activo']['premio_multiplo']);

        // lotto-activo: 38 animales desde tabla juego_opciones
        $this->assertCount(38, $porSlug['lotto-activo']['opciones']);
        $this->assertSame('Delfín', $porSlug['lotto-activo']['opciones'][1]['label'], 'Acentos correctos desde la tabla.');

        // terminal-activo: 100 números 00-99 desde el plugin Terminales
        $this->assertCount(100, $porSlug['terminal-activo']['opciones']);
        $this->assertSame('00', $porSlug['terminal-activo']['opciones'][0]['label']);
        $this->assertSame('99', $porSlug['terminal-activo']['opciones'][99]['label']);

        // tripletas con tabla: 12 signos
        foreach (['triple-zulia', 'triple-caliente', 'triple-chance', 'el-arrejuntado', 'triple-zamorano'] as $slug) {
            $this->assertCount(12, $porSlug[$slug]['opciones'], "[{$slug}] debe tener 12 opciones (tabla).");
        }

        // triple-chance: premio OFICIAL 600 (TRIPLE A/B seco) según el afiche
        // oficial de tuchance.com.ve ("Chance en línea"); fuente migrada al API
        // oficial scalalot en el WU f24.
        $this->assertSame(600, $porSlug['triple-chance']['premio_multiplo']);

        // animalitos sin tabla: 38 animales canónicos desde el plugin Animalitos
        // (el mapa del plugin tiene 38: ballena y delfin comparten numero 0;
        // 37 sería contar los números 0-36, pero son 38 etiquetas — evidencia:
        // JuegoAnimalitosSeeder y la BD local con 38 filas).
        foreach (['lotto-activo-rd', 'lotto-activo-rep-dom', 'cazaloton', 'la-granjita', 'mega-animal-40'] as $slug) {
            $this->assertCount(38, $porSlug[$slug]['opciones'], "[{$slug}] debe tener 38 opciones (plugin Animalitos).");
        }

        // monje-millonario: zoológico PROPIO de 77 figuras confirmadas con la
        // fuente oficial (feed lottoactivo.com, muestreo de 75 días
        // 2026-07-02..09-14, ~900 sorteos; H2 CONFIRMADO + H14 RESUELTO). Los
        // 7 nombres que faltaban quedaron confirmados: 37 Tortuga, 39 Lechuza,
        // 57 Pato, 65 Araña, 67 Avestruz, 68 Jaguar y 75 Patronus (figura
        // especial). Rango completo 0–75 (76 números + 0 duplicado = 77).
        $this->assertCount(77, $porSlug['monje-millonario']['opciones']);
        $labelsMonje = array_column($porSlug['monje-millonario']['opciones'], 'label');
        $this->assertContains('Pereza', $labelsMonje);
        $this->assertContains('Tucán', $labelsMonje);
        $this->assertContains('Turpial', $labelsMonje);
        $this->assertContains('Cebra', $labelsMonje);
        $this->assertContains('Patronus', $labelsMonje);
        $this->assertNotContains('Cobra', $labelsMonje);

        // trio-activo: 100 opciones de TERMINAL (00-99) propias desde la tabla
        // (patrón Triple Fácil H10; el reglamento oficial define TRIPLE 600× /
        // TERMINAL 60× / PUNTA 60× y NO hay zodiaco) y premio TRIPLE 600×.
        $this->assertCount(100, $porSlug['trio-activo']['opciones']);
        $this->assertSame('00', $porSlug['trio-activo']['opciones'][0]['label']);
        $this->assertSame('99', $porSlug['trio-activo']['opciones'][99]['label']);

        // la-ricachona sin tabla: 12 signos desde el plugin Tripletas
        foreach (['la-ricachona'] as $slug) {
            $this->assertCount(12, $porSlug[$slug]['opciones'], "[{$slug}] debe tener 12 opciones (plugin Tripletas).");
        }
        $this->assertSame('Géminis', $porSlug['la-ricachona']['opciones'][2]['label'], 'Acentos correctos desde el plugin.');

        // loto-chaima: 57 animales PROPIOS desde la tabla juego_opciones
        // (zoológico de 0–55 distinto al canónico; ballena y delfín comparten
        // el numero 0; orden por numero → ballena/delfín primero).
        $this->assertCount(57, $porSlug['loto-chaima']['opciones']);
        $this->assertSame('Ballena', $porSlug['loto-chaima']['opciones'][0]['label']);
        $this->assertSame(0, $porSlug['loto-chaima']['opciones'][0]['numero']);
        $this->assertSame('Delfín', $porSlug['loto-chaima']['opciones'][1]['label']);
        $this->assertSame('Ciempiés', $porSlug['loto-chaima']['opciones'][4]['label'], 'Acentos correctos desde la tabla.');
        $this->assertSame('ciempies', $porSlug['loto-chaima']['opciones'][4]['value'], 'value = slug sin acentos.');
        $this->assertSame('Oso Hormiguero', $porSlug['loto-chaima']['opciones'][56]['label']);
        $this->assertSame(55, $porSlug['loto-chaima']['opciones'][56]['numero']);

        // el-guacharito: 101 figuras PROPIAS desde la tabla juego_opciones
        // (zoológico 00 Ballena + 0 Delfin + 01..99 Guacharito; extraído del
        // bundle oficial del sitio — labels SIN acentos como viajan en el bundle)
        // y premio oficial 70x (figura especial Guacharito 99 → 150x).
        // El catálogo ordena por `numero` (MySQL: NULLs y 0 primero), por lo que
        // se valida por value/label, no por posición.
        $this->assertCount(101, $porSlug['el-guacharito']['opciones']);
        $this->assertSame(70, $porSlug['el-guacharito']['premio_multiplo']);

        $labelsGuacharito = array_column($porSlug['el-guacharito']['opciones'], 'label');
        $this->assertContains('Ballena', $labelsGuacharito);
        $this->assertContains('Delfin', $labelsGuacharito);
        $this->assertContains('Gavilan', $labelsGuacharito);
        $this->assertContains('Guacharito', $labelsGuacharito);
        $this->assertNotContains('Delfín', $labelsGuacharito, 'El bundle oficial no usa acentos.');

        $gavilan = collect($porSlug['el-guacharito']['opciones'])->firstWhere('value', 'gavilan');
        $this->assertSame(64, $gavilan['numero']);
        $guacharito99 = collect($porSlug['el-guacharito']['opciones'])->firstWhere('value', 'guacharito');
        $this->assertSame(99, $guacharito99['numero']);

        // guacharo-activo: 77 figuras PROPIAS desde la tabla juego_opciones
        // (zoológico 00 Ballena + 0 Delfín + 01..75 Guacharo; extraído del
        // bundle oficial del sitio — labels CON acentos como viajan en el bundle)
        // y premio oficial 60x (comodín Guácharo 75 → 120x).
        $this->assertCount(77, $porSlug['guacharo-activo']['opciones']);
        $this->assertSame(60, $porSlug['guacharo-activo']['premio_multiplo']);

        $labelsGuacharo = array_column($porSlug['guacharo-activo']['opciones'], 'label');
        $this->assertContains('Ballena', $labelsGuacharo);
        $this->assertContains('Delfín', $labelsGuacharo);
        $this->assertContains('Iguana', $labelsGuacharo);
        $this->assertContains('Guacharo', $labelsGuacharo);

        $iguana = collect($porSlug['guacharo-activo']['opciones'])->firstWhere('value', 'iguana');
        $this->assertSame(24, $iguana['numero']);
        $guacharo75 = collect($porSlug['guacharo-activo']['opciones'])->firstWhere('value', 'guacharo');
        $this->assertSame(75, $guacharo75['numero']);

        // mega-animal-40 sin tabla: 38 animales canónicos desde el plugin Animalitos
        // (zoológico canónico del proveedor: Delfín/Ballena 0 ... Culebra 36; el comodín
        // "MEGA" de 40x NO se modela — premio_multiplo estático 30, ver docs/comparacion-juegos.md).
        // Las labels del plugin son SIN acentos ('Delfin', 'Caiman'), a diferencia de la
        // tabla propia de loto-chaima ('Delfín').
        $this->assertCount(38, $porSlug['mega-animal-40']['opciones']);
        $this->assertSame('Ballena', $porSlug['mega-animal-40']['opciones'][0]['label']);
        $this->assertSame(0, $porSlug['mega-animal-40']['opciones'][0]['numero']);
        $this->assertSame('Delfin', $porSlug['mega-animal-40']['opciones'][1]['label']);
        $this->assertSame(0, $porSlug['mega-animal-40']['opciones'][1]['numero']);
        $this->assertSame('Culebra', $porSlug['mega-animal-40']['opciones'][37]['label']);
        $this->assertSame(36, $porSlug['mega-animal-40']['opciones'][37]['numero']);
        $this->assertSame(30, $porSlug['mega-animal-40']['premio_multiplo']);

        // selva-plus: 103 opciones PROPIAS desde la tabla juego_opciones
        // (101 figuras 0–99 del zoológico propio + 2 comodines con numero null).
        // El catálogo ordena por `numero` (MySQL: NULLs primero) → los comodines
        // quedan al inicio; se valida por value/label, no por posición.
        $this->assertCount(103, $porSlug['selva-plus']['opciones']);
        $this->assertSame(80, $porSlug['selva-plus']['premio_multiplo']);

        $labelsSelva = array_column($porSlug['selva-plus']['opciones'], 'label');
        $this->assertContains('Ballena', $labelsSelva);
        $this->assertContains('Delfín', $labelsSelva);
        $this->assertContains('Cabra', $labelsSelva);
        $this->assertContains('Halcón', $labelsSelva);
        $this->assertContains('Leoncito (comodín A)', $labelsSelva);
        $this->assertContains('Selva Plus (comodín B)', $labelsSelva);

        $comodinA = collect($porSlug['selva-plus']['opciones'])->firstWhere('value', 'comodin-a');
        $comodinB = collect($porSlug['selva-plus']['opciones'])->firstWhere('value', 'comodin-b');
        $this->assertNull($comodinA['numero']);
        $this->assertNull($comodinB['numero']);

        $cabra = collect($porSlug['selva-plus']['opciones'])->firstWhere('value', 'cabra');
        $this->assertSame(87, $cabra['numero']);
        $this->assertSame('Cabra', $cabra['label']);

        // triple-tachira: 12 signos PROPIOS desde la tabla juego_opciones
        // (patrón triple-caliente) y premios OFICIALES del reglamento
        // G-20004065-3 (A/B 500x, cola 50x, zodiacal 5.000x).
        $this->assertCount(12, $porSlug['triple-tachira']['opciones']);
        $this->assertSame(500, $porSlug['triple-tachira']['premio_multiplo']);
        $this->assertSame(
            ['13:15', '16:45', '22:10'],
            $porSlug['triple-tachira']['horarios']
        );

        $labelsTachira = array_column($porSlug['triple-tachira']['opciones'], 'label');
        $this->assertContains('Aries', $labelsTachira);
        $this->assertContains('Piscis', $labelsTachira);
        $this->assertContains('Acuario', $labelsTachira);

        // triple-facil: 100 opciones de TERMINAL PROPIAS desde la tabla
        // juego_opciones (label "00".."99" con padding, value "0".."99" sin
        // padding, numero 0..99 — patrón del plugin Terminales) y premios
        // INFORMATIVOS documentados (el sitio oficial no publica cifras):
        // triple completo 700×, terminal 60×, aproximación 10×.
        $this->assertCount(100, $porSlug['triple-facil']['opciones']);
        $this->assertSame(700, $porSlug['triple-facil']['premio_multiplo']);
        $this->assertSame(
            ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'],
            $porSlug['triple-facil']['horarios']
        );
        $this->assertSame('00', $porSlug['triple-facil']['opciones'][0]['label']);
        $this->assertSame(0, $porSlug['triple-facil']['opciones'][0]['numero']);
        $this->assertSame('99', $porSlug['triple-facil']['opciones'][99]['label']);
        $this->assertSame(99, $porSlug['triple-facil']['opciones'][99]['numero']);

        // triple-zamorano: 12 signos PROPIOS desde la tabla juego_opciones
        // (patrón triple-caliente), premio_multiplo 30 (default de los triples;
        // la informativa declara 600x/60x/6.000x SIN fuente oficial verificada,
        // pendiente) y 5 horarios oficiales 10:00/12:00/14:00/16:00/19:00.
        $this->assertCount(12, $porSlug['triple-zamorano']['opciones']);
        $this->assertSame(30, $porSlug['triple-zamorano']['premio_multiplo']);
        $this->assertSame(
            ['10:00', '12:00', '14:00', '16:00', '19:00'],
            $porSlug['triple-zamorano']['horarios']
        );

        $labelsZamorano = array_column($porSlug['triple-zamorano']['opciones'], 'label');
        $this->assertContains('Aries', $labelsZamorano);
        $this->assertContains('Piscis', $labelsZamorano);
        $this->assertContains('Acuario', $labelsZamorano);
    }

    public function test_ids_de_los_juegos_coinciden_con_el_orden_del_seeder(): void
    {
        // El ARCHIVO es el contrato: ids reales 1-14 en el orden del DatabaseSeeder.
        $idsArchivo = collect($this->archivo['juegos'])->pluck('slug', 'id')->all();
        $this->assertSame(
            self::SLUGS_POR_ID,
            $idsArchivo,
            'Los ids del archivo commiteado deben coincidir con el orden de siembra del DatabaseSeeder.'
        );

        // El catálogo GENERADO en la BD de tests conserva el MISMO orden relativo
        // (slugs por id ascendente) pero los ids absolutos pueden quedar corridos
        // por el auto-increment de MySQL que no retrocede con el rollback de
        // RefreshDatabase (evidencia: corrida 27-39 en suite compartida).
        $juegosGenerados = collect($this->generado['juegos'])->sortBy('id')->values();
        $this->assertSame(
            array_values(self::SLUGS_POR_ID),
            $juegosGenerados->pluck('slug')->all(),
            'Los slugs generados (por id ascendente) deben seguir el orden del DatabaseSeeder.'
        );

        $idsGenerados = $juegosGenerados->pluck('id')->all();
        $idsConsecutivos = $idsGenerados;
        sort($idsConsecutivos);
        $this->assertSame($idsConsecutivos, $idsGenerados, 'Los ids generados deben ser consecutivos (sin huecos ni juegos extra).');
        for ($i = 1; $i < count($idsGenerados); $i++) {
            $this->assertSame(1, $idsGenerados[$i] - $idsGenerados[$i - 1], 'Los ids generados deben ser estrictamente consecutivos.');
        }
        $this->assertSame(21, count($idsGenerados), 'Deben ser exactamente 21 juegos.');
    }
}
