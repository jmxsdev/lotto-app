<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos\SelvaPlus;
use App\Services\JuegoPluginManager;
use App\Support\PremiosOficiales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selva Plus: el comodín A/B es una figura apostable independiente.
 *
 * Forma real del scraper (SelvaPlusScraper::numerosGanadores):
 *   {pais:"VE", comodin:"A"|"B", comodin_nombre:"Leoncito"|"Selva Plus"}
 * No hay `nombre_animal` en un sorteo con comodín. El nombre oficial lo
 * resuelve la UI/el scraper, no la fuente.
 */
class SelvaPlusPluginTest extends TestCase
{
    use RefreshDatabase;

    private function plugin(): SelvaPlus
    {
        return new SelvaPlus;
    }

    /**
     * @param  array<string, mixed>  $numeros
     * @return array<string, mixed>
     */
    private function resultado(array $numeros): array
    {
        return ['numeros_ganadores' => $numeros];
    }

    private function juego(): Juego
    {
        $juego = Juego::create([
            'name' => 'Selva Plus',
            'slug' => 'selva-plus',
            'type' => 'animalitos',
            'config' => PremiosOficiales::configPara('selva-plus'),
            'requires_scraper' => true,
            'active' => true,
        ]);

        PluginJuego::create([
            'juego_id' => $juego->id,
            'class_namespace' => SelvaPlus::class,
            'version' => '1.1.0',
            'active' => true,
        ]);

        return $juego;
    }

    public function test_comodin_a_gana_con_la_forma_real_del_scraper(): void
    {
        $acierto = $this->plugin()->evaluarAcierto(
            ['combinacion' => ['animal' => 'comodin-a']],
            $this->resultado(['pais' => 'VE', 'comodin' => 'A', 'comodin_nombre' => 'Leoncito'])
        );

        $this->assertTrue($acierto['coincide']);
        $this->assertSame('base', $acierto['clave']);
        $this->assertContains('comodin-a', $acierto['meta']['comodines']);
    }

    public function test_comodin_b_gana_con_la_forma_real_del_scraper(): void
    {
        $acierto = $this->plugin()->evaluarAcierto(
            ['combinacion' => ['animal' => 'comodin-b']],
            $this->resultado(['pais' => 'VE', 'comodin' => 'B', 'comodin_nombre' => 'Selva Plus'])
        );

        $this->assertTrue($acierto['coincide']);
        $this->assertContains('comodin-b', $acierto['meta']['comodines']);
    }

    public function test_label_oficial_del_comodin_tambien_gana(): void
    {
        $acierto = $this->plugin()->evaluarAcierto(
            ['combinacion' => ['animal' => 'Leoncito (comodín A)']],
            $this->resultado(['pais' => 'VE', 'comodin' => 'A', 'comodin_nombre' => 'Leoncito'])
        );

        $this->assertTrue($acierto['coincide']);
        $this->assertContains('comodin-a', $acierto['meta']['comodines']);
    }

    public function test_apuesta_a_animal_pierde_en_sorteo_con_comodin(): void
    {
        $acierto = $this->plugin()->evaluarAcierto(
            ['combinacion' => ['animal' => 'Perro']],
            $this->resultado(['pais' => 'VE', 'comodin' => 'A', 'comodin_nombre' => 'Leoncito'])
        );

        $this->assertFalse($acierto['coincide']);
    }

    public function test_apuesta_al_comodin_pierde_contra_resultado_numerico(): void
    {
        $acierto = $this->plugin()->evaluarAcierto(
            ['combinacion' => ['animal' => 'comodin-a']],
            $this->resultado(['pais' => 'VE', 'numero' => 5, 'nombre_animal' => 'León'])
        );

        $this->assertFalse($acierto['coincide']);
    }

    public function test_engine_paga_160x_con_comodin_a(): void
    {
        $juego = $this->juego();

        $premio = app(JuegoPluginManager::class)->calcularPremio(
            $juego,
            [
                'combinacion' => ['animal' => 'comodin-a'],
                'amount_bs' => 10,
                'amount_usd' => 0,
            ],
            $this->resultado(['pais' => 'VE', 'comodin' => 'A', 'comodin_nombre' => 'Leoncito'])
        );

        $this->assertSame(1600.0, $premio['premio_bs']);
        $this->assertSame(0.0, $premio['premio_usd']);
    }

    public function test_engine_paga_200x_con_comodin_b(): void
    {
        $juego = $this->juego();

        $premio = app(JuegoPluginManager::class)->calcularPremio(
            $juego,
            [
                'combinacion' => ['animal' => 'comodin-b'],
                'amount_bs' => 10,
                'amount_usd' => 0,
            ],
            $this->resultado(['pais' => 'VE', 'comodin' => 'B', 'comodin_nombre' => 'Selva Plus'])
        );

        $this->assertSame(2000.0, $premio['premio_bs']);
    }

    public function test_engine_no_paga_animal_en_sorteo_con_comodin(): void
    {
        $juego = $this->juego();

        $premio = app(JuegoPluginManager::class)->calcularPremio(
            $juego,
            [
                'combinacion' => ['animal' => 'Perro'],
                'amount_bs' => 10,
                'amount_usd' => 0,
            ],
            $this->resultado(['pais' => 'VE', 'comodin' => 'A', 'comodin_nombre' => 'Leoncito'])
        );

        $this->assertSame(0.0, $premio['premio_bs']);
    }

    public function test_opciones_incluyen_101_figuras_y_2_comodines(): void
    {
        $opciones = $this->plugin()->obtenerOpciones();

        $this->assertCount(103, $opciones);
        $this->assertSame('comodin-a', $opciones[101]['value']);
        $this->assertSame('comodin-b', $opciones[102]['value']);
        $this->assertNull($opciones[101]['numero']);
    }

    public function test_selva_no_vende_tripleta(): void
    {
        $this->assertFalse($this->plugin()->validarApuesta([
            'combinacion' => [
                'modalidad' => 'tripleta',
                'selecciones' => [
                    ['animal' => 'Perro'],
                    ['animal' => 'Gato'],
                    ['animal' => 'León'],
                ],
            ],
        ], null));
    }
}
