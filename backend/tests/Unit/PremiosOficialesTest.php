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
}