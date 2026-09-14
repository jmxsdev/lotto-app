<?php

namespace Tests\Feature;

use App\Models\Juego;
use App\Models\JuegoOpcion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verificación integral (WU f22) — los 7 juegos originales contra sus fuentes
 * oficiales (lottoactivo.com / resultadostriplezulia.com). Cada aserción tiene
 * su evidencia documentada en docs/seguimiento-verificacion.md (feed oficial
 * muestreado 2026-09-10..14, reglamentos PDF del sitio oficial y FAQ oficial).
 */
class VerificacionOriginalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** @return Collection<int, JuegoOpcion> */
    private function opcionesDe(string $slug)
    {
        return Juego::where('slug', $slug)->firstOrFail()->opciones()->orderBy('numero')->get();
    }

    public function test_lotto_activo_zoo_canonico_usa_cebra_en_23(): void
    {
        // Evidencia: feed oficial lottoactivo.com /resultados/animalitos/
        // (2026-09-10..14) muestra name_animal "Cebra" para el número 23 en los
        // 4 juegos animalitos; el reglamento Ruleta Royal (PDF oficial
        // Lotto_Activo_Rd_Ve.pdf) declara "Cebra = 23". Nuestro canónico decía
        // "Cobra" → corregido.
        $opciones = $this->opcionesDe('lotto-activo');
        $this->assertCount(38, $opciones);

        $cebra = $opciones->firstWhere('numero', 23);
        $this->assertNotNull($cebra, 'El número 23 debe existir.');
        $this->assertSame('Cebra', $cebra->label);
        $this->assertSame('cebra', $cebra->value);
        $this->assertNull($opciones->firstWhere('value', 'cobra'), 'No debe quedar la opción obsoleta "cobra".');
    }

    public function test_monje_millonario_tiene_zoo_propio_completo(): void
    {
        // Evidencia (H14 RESUELTO con fuente oficial): muestreo del feed
        // lottoactivo.com /resultados/animalitos/ de 75 días consecutivos
        // (2026-07-02..09-14, ~900 sorteos de Monje) → aparecen TODOS los
        // números 0–75 y quedan confirmados los 7 nombres que faltaban:
        // 37 Tortuga, 39 Lechuza, 57 Pato, 65 Arana→Araña, 67 Avestruz,
        // 68 Jaguar y 75 Patronus (la figura especial que declara la
        // informativa; 4 apariciones en la muestra). El zoo queda COMPLETO:
        // 77 etiquetas (76 números 0–75 + el 0 duplicado Delfín/Ballena).
        $opciones = $this->opcionesDe('monje-millonario');
        $this->assertCount(77, $opciones);

        $confirmados = [
            37 => 'Tortuga',
            39 => 'Lechuza',
            57 => 'Pato',
            65 => 'Araña',
            67 => 'Avestruz',
            68 => 'Jaguar',
            75 => 'Patronus',
        ];
        foreach ($confirmados as $numero => $label) {
            $opcion = $opciones->firstWhere('numero', $numero);
            $this->assertNotNull($opcion, "El número {$numero} debe existir en el zoo.");
            $this->assertSame($label, $opcion->label, "El número {$numero} debe ser {$label}.");
        }

        $this->assertSame('Pereza', $opciones->firstWhere('numero', 49)->label);
        $this->assertSame('Tucán', $opciones->firstWhere('numero', 42)->label);
        $this->assertNotNull($opciones->firstWhere('numero', 74), 'Turpial (74) observado en el feed oficial.');
        $this->assertNotNull($opciones->firstWhere('numero', 0), 'Delfín/Ballena (0) canónico.');
        $this->assertNull($opciones->firstWhere('value', 'cobra'), '23 debe ser Cebra también en Monje.');
    }

    public function test_monje_millonario_zoo_cubre_todos_los_numeros_0_a_75(): void
    {
        // Evidencia: el muestreo de 75 días del feed oficial cubre el rango
        // COMPLETO 0–75 sin huecos. La informativa declara 77 figuras = 76
        // números (0–75) + la etiqueta extra del 0 (Delfín y Ballena comparten
        // el 0). Si el feed dejara de servir algún número, este test lo delata.
        $opciones = $this->opcionesDe('monje-millonario');

        $numeros = $opciones->pluck('numero')->map(fn ($n) => (int) $n)->unique()->sort()->values()->all();
        $this->assertSame(range(0, 75), $numeros, 'El zoo debe cubrir 0..75 sin huecos.');

        // 77 etiquetas para 76 números distintos: el 0 tiene Delfín y Ballena.
        $this->assertCount(76, $numeros);
        $this->assertCount(2, $opciones->where('numero', 0), 'El 0 lo comparten Delfín y Ballena.');
    }

    public function test_trio_activo_es_triple_con_opciones_de_terminal_y_premio_600(): void
    {
        // Evidencia (H5 DESMENTIDO con fuente oficial):
        // - feed oficial /resultados/trio_activo/: 12 sorteos diarios 08:00–19:00
        //   de un TRIPLE de 3 cifras (resultado1 "491"); NO son terminales 3 cifras.
        // - reglamento oficial Trio_Activo.pdf: modalidades TRIPLE (600×),
        //   TERMINAL (2 últimos dígitos, 60×) y PUNTA (2 primeros, 60×); NO hay
        //   zodiaco → las 12 opciones de signos del plugin eran incorrectas.
        // - FAQ oficial: "Trío Activo... hasta 600 veces".
        $juego = Juego::where('slug', 'trio-activo')->firstOrFail();
        $this->assertSame('tripletas', $juego->type);
        $this->assertSame(600, $juego->config['premio_multiplo']);
        $this->assertEqualsCanonicalizing(['terminal' => 60, 'punta' => 60], $juego->config['modalidades']);

        // Opciones del TERMINAL real (00-99, patrón Triple Fácil H10): el triple
        // 000-999 es entrada libre y queda documentado en config.
        $opciones = $this->opcionesDe('trio-activo');
        $this->assertCount(100, $opciones);
        $this->assertSame('00', $opciones->first()->label);
        $this->assertSame('99', $opciones->last()->label);

        // 12 horarios oficiales 08:00–19:00 (feed).
        $horarios = $juego->horarios()->orderBy('hora')->pluck('hora')->map(fn ($h) => substr($h, 0, 5))->all();
        $this->assertSame(
            ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00'],
            $horarios
        );
    }

    public function test_terminal_activo_premio_60_del_reglamento(): void
    {
        // Evidencia: reglamento oficial Trio_Activo.pdf (idéntico al enlazado
        // como Terminal_Trio.pdf en el sitio oficial — mismo md5) declara la
        // modalidad TERMINAL en 60×. La informativa coincide (60×). El FAQ
        // oficial declara 70× + 5× aproximación → discrepancia documentada
        // (H12); se usa el valor del reglamento (patrón Táchira).
        $juego = Juego::where('slug', 'terminal-activo')->firstOrFail();
        $this->assertSame(60, $juego->config['premio_multiplo']);

        // 12 horarios oficiales 08:00–19:00 (feed) y 100 opciones 00-99 (plugin).
        $this->assertCount(12, $juego->horarios);
    }

    public function test_triple_zulia_horarios_y_signos_oficiales(): void
    {
        // Evidencia: API oficial resultadostriplezulia.com (product_id 2):
        // 234 respuestas con SOLO 3 horarios 12:45/16:45/19:05 y A/B/C donde C =
        // triple+signo con los 12 zodiacales. Coincide con nuestro seeder.
        $juego = Juego::where('slug', 'triple-zulia')->firstOrFail();
        $horarios = $juego->horarios()->orderBy('hora')->pluck('hora')->map(fn ($h) => substr($h, 0, 5))->all();
        $this->assertSame(['12:45', '16:45', '19:05'], $horarios);
        $this->assertCount(12, $this->opcionesDe('triple-zulia'));
    }

    public function test_familia_lotto_activo_horarios_oficiales(): void
    {
        // Evidencia: feed oficial 2026-09-10..14.
        $rd = Juego::where('slug', 'lotto-activo-rd')->firstOrFail();
        $horariosRd = $rd->horarios()->orderBy('hora')->pluck('hora')->map(fn ($h) => substr($h, 0, 5))->all();
        $this->assertSame(
            ['08:30', '09:30', '10:30', '11:30', '12:30', '13:30', '14:30', '15:30', '16:30', '17:30', '18:30', '19:30'],
            $horariosRd,
            'RD Internacional: 12 sorteos cada hora 08:30-19:30 (feed + metadata oficial).'
        );

        $repDom = Juego::where('slug', 'lotto-activo-rep-dom')->firstOrFail();
        $horariosRepDom = $repDom->horarios()->orderBy('hora')->pluck('hora')->map(fn ($h) => substr($h, 0, 5))->all();
        $this->assertSame(
            ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00'],
            $horariosRepDom,
            'República Dominicana: 14 sorteos 08:00-21:00 (feed oficial).'
        );

        $lotto = Juego::where('slug', 'lotto-activo')->firstOrFail();
        $this->assertCount(12, $lotto->horarios, 'Lotto Activo: 12 sorteos 08:00-19:00 (feed oficial).');

        $monje = Juego::where('slug', 'monje-millonario')->firstOrFail();
        $this->assertCount(12, $monje->horarios, 'Monje Millonario: 12 sorteos 08:05-19:05 (feed oficial).');
    }
}
