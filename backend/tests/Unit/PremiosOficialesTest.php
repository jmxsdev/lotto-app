<?php

namespace Tests\Unit;

use App\Support\PremiosOficiales;
use Tests\TestCase;

class PremiosOficialesTest extends TestCase
{
    /**
     * Claves canónicas del vocabulario del diseño §3.1, más las claves
     * `triple_a`/`triple_b` que introduce la tabla §3.2 (El Arrejuntado).
     * La Dupleta NO pertenece al vocabulario (fuera de alcance).
     */
    private const CLAVES_CANONICAS = [
        'base', 'tripleta', 'terminal', 'punta', 'uña', 'signo_triple',
        'signo_terminal', 'signo_uña', 'signo_solo', 'triple_a_b', 'solo_a_b',
        'cruzado', 'cruzado_10', 'arrimao', 'pegadito', 'aproximacion',
        'triple_a', 'triple_b',
    ];

    public function test_catalogo_cubre_los_21_juegos_oficiales()
    {
        $esperados = [
            'lotto-activo', 'lotto-activo-rd', 'lotto-activo-rep-dom',
            'terminal-activo', 'monje-millonario', 'trio-activo', 'triple-zulia',
            'triple-caliente', 'triple-chance', 'triple-tachira', 'triple-facil',
            'triple-zamorano', 'el-arrejuntado', 'cazaloton', 'loto-chaima',
            'el-guacharito', 'guacharo-activo', 'mega-animal-40', 'selva-plus',
            'la-granjita', 'la-ricachona',
        ];

        $slugs = PremiosOficiales::slugs();

        sort($esperados);
        sort($slugs);

        $this->assertCount(21, $slugs);
        $this->assertSame($esperados, $slugs);
    }

    public function test_familia_lotto_activo_base_30_sin_modalidades()
    {
        foreach (['lotto-activo', 'lotto-activo-rd', 'lotto-activo-rep-dom'] as $slug) {
            $juego = PremiosOficiales::para($slug);
            $this->assertNotNull($juego, "Falta {$slug} en el catálogo");
            $this->assertSame(30, $juego['base'], "Base de {$slug}");
            $this->assertSame([], $juego['modalidades']);
            $this->assertSame([], $juego['comodines']);
            $this->assertTrue($juego['active']);
        }
    }

    public function test_terminal_activo_base_60()
    {
        $juego = PremiosOficiales::para('terminal-activo');
        $this->assertSame(60, $juego['base']);
        $this->assertSame([], $juego['modalidades']);
        $this->assertSame([], $juego['comodines']);
    }

    public function test_trio_activo_base_600_con_terminal_y_punta()
    {
        $juego = PremiosOficiales::para('trio-activo');
        $this->assertSame(600, $juego['base']);
        $this->assertSame(['terminal' => 60, 'punta' => 60], $juego['modalidades']);
    }

    public function test_monje_millonario_base_50_con_comodines_patronus()
    {
        $juego = PremiosOficiales::para('monje-millonario');
        $this->assertSame(50, $juego['base']);
        $this->assertSame([], $juego['modalidades']);

        $this->assertSame([
            'tipo' => 'numero',
            'premio_multiplo' => 120,
            'numero' => 75,
            'nombre' => 'Patronus',
        ], $juego['comodines']['patronus-75']);

        $this->assertSame([
            'tipo' => 'palabra',
            'premio_multiplo' => 20,
            'acumulativo' => true,
            'nombre' => 'PATRONUS',
        ], $juego['comodines']['patronus-palabra']);
    }

    public function test_triple_zulia_y_triple_caliente_600_con_terminal_signo()
    {
        foreach (['triple-zulia', 'triple-caliente'] as $slug) {
            $juego = PremiosOficiales::para($slug);
            $this->assertSame(600, $juego['base'], "Base de {$slug}");
            $this->assertSame([
                'terminal' => 60,
                'signo_triple' => 6000,
                'signo_terminal' => 600,
            ], $juego['modalidades']);
        }
    }

    public function test_triple_chance_600_con_todas_las_modalidades_del_reglamento()
    {
        $juego = PremiosOficiales::para('triple-chance');
        $this->assertSame(600, $juego['base']);
        $this->assertSame([
            'triple_a_b' => 200000,
            'solo_a_b' => 150,
            'punta' => 60,
            'terminal' => 60,
            'cruzado' => 3000,
            'cruzado_10' => 10,
            'signo_triple' => 6000,
            'signo_terminal' => 600,
            'signo_solo' => 6,
        ], $juego['modalidades']);
    }

    public function test_triple_tachira_500_con_terminal_y_zodiacal()
    {
        $juego = PremiosOficiales::para('triple-tachira');
        $this->assertSame(500, $juego['base']);
        $this->assertSame(['terminal' => 50, 'signo_triple' => 5000], $juego['modalidades']);
    }

    public function test_triple_facil_700_con_terminal_y_aproximacion()
    {
        $juego = PremiosOficiales::para('triple-facil');
        $this->assertSame(700, $juego['base']);
        $this->assertSame(['terminal' => 60, 'aproximacion' => 10], $juego['modalidades']);
    }

    public function test_triple_zamorano_600_con_unas_y_signos()
    {
        $juego = PremiosOficiales::para('triple-zamorano');
        $this->assertSame(600, $juego['base']);
        $this->assertSame([
            'terminal' => 60,
            'uña' => 5,
            'signo_triple' => 6000,
            'signo_terminal' => 600,
            'signo_uña' => 60,
        ], $juego['modalidades']);
    }

    public function test_el_arrejuntado_base_40_con_modalidades_6000_y_60000()
    {
        $juego = PremiosOficiales::para('el-arrejuntado');
        $this->assertSame(40, $juego['base']);
        $this->assertSame([
            'triple_a' => 600,
            'triple_b' => 600,
            'signo_triple' => 6000,
            'arrimao' => 6000,
            'pegadito' => 60000,
        ], $juego['modalidades']);
    }

    public function test_cazaloton_30_con_tripleta_200_sin_dupleta()
    {
        $juego = PremiosOficiales::para('cazaloton');
        $this->assertSame(30, $juego['base']);
        $this->assertSame(['tripleta' => 200], $juego['modalidades']);
    }

    public function test_loto_chaima_40_con_tripleta_50()
    {
        $juego = PremiosOficiales::para('loto-chaima');
        $this->assertSame(40, $juego['base']);
        $this->assertSame(['tripleta' => 50], $juego['modalidades']);
    }

    public function test_el_guacharito_70_con_comodin_numero_99_a_150()
    {
        $juego = PremiosOficiales::para('el-guacharito');
        $this->assertSame(70, $juego['base']);
        $this->assertSame([
            'tipo' => 'numero',
            'premio_multiplo' => 150,
            'numero' => 99,
            'nombre' => 'Guacharito',
        ], $juego['comodines']['guacharito-99']);
    }

    public function test_guacharo_activo_60_con_comodin_numero_75_a_120()
    {
        $juego = PremiosOficiales::para('guacharo-activo');
        $this->assertSame(60, $juego['base']);
        $this->assertSame([
            'tipo' => 'numero',
            'premio_multiplo' => 120,
            'numero' => 75,
            'nombre' => 'Guácharo',
        ], $juego['comodines']['guacharo-75']);
    }

    public function test_mega_animal_40_30_con_comodin_flag_mega_40()
    {
        $juego = PremiosOficiales::para('mega-animal-40');
        $this->assertSame(30, $juego['base']);
        $this->assertSame([
            'tipo' => 'flag',
            'premio_multiplo' => 40,
            'nombre' => 'MEGA',
        ], $juego['comodines']['mega']);
    }

    public function test_selva_plus_80_con_comodines_letra_a_y_b()
    {
        $juego = PremiosOficiales::para('selva-plus');
        $this->assertSame(80, $juego['base']);
        $this->assertSame([
            'tipo' => 'letra',
            'premio_multiplo' => 160,
            'valor' => 'A',
            'nombre' => 'Leoncito',
        ], $juego['comodines']['comodin-a']);
        $this->assertSame([
            'tipo' => 'letra',
            'premio_multiplo' => 200,
            'valor' => 'B',
            'nombre' => 'Selva Plus',
        ], $juego['comodines']['comodin-b']);
    }

    public function test_la_granjita_30_sin_modalidades_ni_comodines()
    {
        $juego = PremiosOficiales::para('la-granjita');
        $this->assertSame(30, $juego['base']);
        $this->assertSame([], $juego['modalidades']);
        $this->assertSame([], $juego['comodines']);
    }

    public function test_la_ricachona_sin_premios_e_inactiva()
    {
        $juego = PremiosOficiales::para('la-ricachona');
        $this->assertArrayNotHasKey('base', $juego);
        $this->assertSame([], $juego['modalidades']);
        $this->assertSame([], $juego['comodines']);
        $this->assertFalse($juego['active']);
    }

    public function test_todas_las_modalidades_usan_vocabulario_canonico_sin_dupleta()
    {
        foreach (PremiosOficiales::todos() as $slug => $juego) {
            foreach (array_keys($juego['modalidades']) as $clave) {
                $this->assertContains(
                    $clave,
                    self::CLAVES_CANONICAS,
                    "Modalidad '{$clave}' de {$slug} no pertenece al vocabulario canónico"
                );
            }
            foreach (array_keys($juego['comodines']) as $clave) {
                $this->assertNotSame('dupleta', $clave, "Dupleta fuera de alcance en {$slug}");
                $this->assertStringNotContainsString('dupleta', $clave, "Clave con 'dupleta' en {$slug}");
            }
        }
    }

    public function test_para_devuelve_null_para_slug_desconocido()
    {
        $this->assertNull(PremiosOficiales::para('juego-inexistente'));
    }

    // ==================================================
    // configPara(): fuente única para migración y seeders (D2/D4)
    // ==================================================

    public function test_config_para_lotto_activo_escribe_premios_y_espejos_vacios()
    {
        $config = PremiosOficiales::configPara('lotto-activo');

        $this->assertSame(30, $config['premio_multiplo']);
        $this->assertSame([
            'base' => 30,
            'modalidades' => [],
            'comodines' => [],
        ], $config['premios']);
        $this->assertSame([], $config['modalidades']);
        $this->assertSame([], $config['comodines']);
    }

    public function test_config_para_trio_activo_mantiene_claves_legacy_y_premios_canonicos()
    {
        $config = PremiosOficiales::configPara('trio-activo');

        $this->assertSame(600, $config['premio_multiplo']);
        $this->assertSame([
            'base' => 600,
            'modalidades' => ['terminal' => 60, 'punta' => 60],
            'comodines' => [],
        ], $config['premios']);
        // Espejo legacy: claves históricas, mismos valores (§3.1).
        $this->assertSame(['terminal' => 60, 'punta' => 60], $config['modalidades']);
    }

    public function test_config_para_triple_zulia_mapea_vocabulario_canonico_a_espejo_legacy()
    {
        $config = PremiosOficiales::configPara('triple-zulia');

        // terminal→cola, signo_triple→zodiacal, signo_terminal→terminal_zodiacal.
        $this->assertSame(['cola' => 60, 'zodiacal' => 6000, 'terminal_zodiacal' => 600], $config['modalidades']);
        $this->assertSame([
            'base' => 600,
            'modalidades' => ['terminal' => 60, 'signo_triple' => 6000, 'signo_terminal' => 600],
            'comodines' => [],
        ], $config['premios']);
    }

    public function test_config_para_triple_zamorano_mapea_todas_las_claves_de_signo()
    {
        $config = PremiosOficiales::configPara('triple-zamorano');

        $this->assertSame([
            'cola' => 60,
            'uña' => 5,
            'zodiacal' => 6000,
            'cola_signo' => 600,
            'uña_signo' => 60,
        ], $config['modalidades']);
    }

    public function test_config_para_triple_chance_alinea_valores_del_reglamento_h23()
    {
        $config = PremiosOficiales::configPara('triple-chance');

        // 100→150 (solo A/B) y 5.000→6.000 (C+Signo) según el reglamento (H23).
        $this->assertSame(150, $config['modalidades']['triple_a_o_b']);
        $this->assertSame(6000, $config['modalidades']['triple_c_signo']);
        $this->assertSame(200000, $config['modalidades']['triple_a_b']);
        $this->assertSame(60, $config['modalidades']['terminal']);
        $this->assertSame(6, $config['modalidades']['signo']);
        $this->assertSame(600, $config['premio_multiplo']);
    }

    public function test_config_para_monje_millonario_incluye_comodines_con_tipo()
    {
        $config = PremiosOficiales::configPara('monje-millonario');

        $this->assertSame(50, $config['premio_multiplo']);
        $this->assertSame('numero', $config['comodines']['patronus-75']['tipo']);
        $this->assertSame(120, $config['comodines']['patronus-75']['premio_multiplo']);
        $this->assertSame('palabra', $config['comodines']['patronus-palabra']['tipo']);
        $this->assertTrue($config['comodines']['patronus-palabra']['acumulativo']);
        $this->assertSame(20, $config['comodines']['patronus-palabra']['premio_multiplo']);
    }

    public function test_config_para_terminal_activo_preserva_clave_legacy_con_valor_de_base()
    {
        $config = PremiosOficiales::configPara('terminal-activo');

        // El acierto del juego es `terminal` (N1); las modalidades canónicas
        // quedan vacías y el motor resuelve por base. El espejo legacy conserva
        // la clave histórica del export con el valor derivado de base (60).
        $this->assertSame(['terminal' => 60], $config['modalidades']);
        $this->assertSame([], $config['premios']['modalidades']);
    }

    public function test_config_para_la_ricachona_sin_premios_ni_multiplicador()
    {
        $config = PremiosOficiales::configPara('la-ricachona');

        // REQ7: sin fuente oficial → sin premios canónicos ni espejo legacy de base.
        $this->assertArrayNotHasKey('premios', $config);
        $this->assertArrayNotHasKey('premio_multiplo', $config);
        $this->assertSame([], $config['modalidades']);
        $this->assertSame([], $config['comodines']);
    }

    public function test_config_para_cubre_los_21_juegos_y_slug_desconocido_devuelve_vacio()
    {
        foreach (PremiosOficiales::slugs() as $slug) {
            $this->assertIsArray(PremiosOficiales::configPara($slug), "configPara({$slug}) debe ser array");
        }

        $this->assertSame([], PremiosOficiales::configPara('juego-inexistente'));
    }

    // ==================================================
    // espejosLegacy(): espejos a partir de premios editados (D2)
    // ==================================================

    public function test_espejos_legacy_mapea_vocabulario_canonico_a_claves_historicas()
    {
        $espejo = PremiosOficiales::espejosLegacy('triple-zulia', [
            'base' => 600,
            'modalidades' => ['terminal' => 60, 'signo_triple' => 6000, 'signo_terminal' => 600],
            'comodines' => [],
        ]);

        $this->assertSame(600, $espejo['premio_multiplo']);
        $this->assertSame(['cola' => 60, 'zodiacal' => 6000, 'terminal_zodiacal' => 600], $espejo['modalidades']);
        $this->assertSame([], $espejo['comodines']);
    }

    public function test_espejos_legacy_terminal_activo_deriva_clave_extra_de_base()
    {
        $espejo = PremiosOficiales::espejosLegacy('terminal-activo', [
            'base' => 60,
            'modalidades' => [],
            'comodines' => [],
        ]);

        $this->assertSame(60, $espejo['premio_multiplo']);
        $this->assertSame(['terminal' => 60], $espejo['modalidades']);
    }

    public function test_espejos_legacy_sin_base_no_emite_premio_multiplo()
    {
        $espejo = PremiosOficiales::espejosLegacy('la-ricachona', [
            'modalidades' => [],
            'comodines' => [],
        ]);

        $this->assertArrayNotHasKey('premio_multiplo', $espejo);
        $this->assertSame([], $espejo['modalidades']);
        $this->assertSame([], $espejo['comodines']);
    }
}
