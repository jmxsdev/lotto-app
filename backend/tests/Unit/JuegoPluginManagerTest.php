<?php

namespace Tests\Unit;

use App\Models\Juego;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Juegos\Terminales;
use App\Services\JuegoPluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fachada JuegoPluginManager sobre PremiosEngine (D1/C, design §3.3).
 *
 * El manager conserva la firma legacy `calcularPremio(Juego, …)` /
 * `getMultiplicador(Juego)` pero DELEGA el dinero en PremiosEngine
 * (REQ1: ningún plugin hardcodea el multiplicador). `validarApuesta`
 * carga las opciones reales del juego (`juego_opciones`, fallback
 * `plugin->obtenerOpciones()`) y las pasa al plugin (REQ15/N12: se valida
 * contra el zoológico del juego, no contra el mapa canónico).
 */
class JuegoPluginManagerTest extends TestCase
{
    use RefreshDatabase;

    private function crearJuegoConPlugin(array $config, string $pluginClass = Animalitos::class, array $juegoData = []): Juego
    {
        $juego = Juego::create(array_merge([
            'name' => 'Juego Test',
            'slug' => 'juego-test',
            'type' => 'animalitos',
            'config' => $config,
            'requires_scraper' => true,
            'active' => true,
        ], $juegoData));

        PluginJuego::create([
            'juego_id' => $juego->id,
            'class_namespace' => $pluginClass,
            'version' => '1.0.0',
            'active' => true,
        ]);

        return $juego;
    }

    // ---------------- getMultiplicador: delega en el engine (REQ1) ----------------

    public function test_get_multiplicador_devuelve_el_base_desde_config_no_el_del_plugin()
    {
        // Terminales hardcodea 20 internamente; el reglamento paga 60 (N1/REQ3).
        // El manager debe devolver el base de config.premios, nunca el del plugin.
        $juego = $this->crearJuegoConPlugin(
            ['premios' => ['base' => 60, 'modalidades' => [], 'comodines' => []]],
            Terminales::class
        );

        $multiplicador = app(JuegoPluginManager::class)->getMultiplicador($juego);

        $this->assertSame(60.0, $multiplicador);
    }

    public function test_get_multiplicador_devuelve_el_base_de_otro_juego()
    {
        $juego = $this->crearJuegoConPlugin(
            ['premios' => ['base' => 50, 'modalidades' => [], 'comodines' => []]],
            Animalitos::class
        );

        $multiplicador = app(JuegoPluginManager::class)->getMultiplicador($juego);

        $this->assertSame(50.0, $multiplicador);
    }

    public function test_get_multiplicador_devuelve_0_sin_premios_configurados()
    {
        $juego = $this->crearJuegoConPlugin([], Animalitos::class);

        $multiplicador = app(JuegoPluginManager::class)->getMultiplicador($juego);

        $this->assertSame(0.0, $multiplicador);
    }

    // ---------------- validarApuesta: opciones reales del juego (REQ15/N12) ----------------

    public function test_validar_apuesta_valida_contra_el_zoo_propio_del_juego()
    {
        // Zoo propio (p. ej. Monje 77 figuras): 'jaguar' NO está en el mapa
        // canónico de Animalitos (36 animales) pero SÍ en juego_opciones.
        $juego = $this->crearJuegoConPlugin(
            ['premios' => ['base' => 50, 'modalidades' => [], 'comodines' => []]],
            Animalitos::class
        );
        JuegoOpcion::create([
            'juego_id' => $juego->id,
            'label' => 'Jaguar',
            'value' => 'jaguar',
            'numero' => 77,
            'active' => true,
            'sort_order' => 1,
        ]);

        $valido = app(JuegoPluginManager::class)->validarApuesta(
            $juego,
            ['combinacion' => ['animal' => 'jaguar']]
        );

        $this->assertTrue($valido, 'El zoo propio debe habilitar animales no canónicos');
    }

    public function test_validar_apuesta_rechaza_animal_ausente_del_zoo_propio_aunque_sea_canonico()
    {
        // 'Delfín' está en el mapa canónico, pero el zoo propio del juego solo
        // tiene 'Jaguar' → la validación debe usar el zoo, no el canónico.
        $juego = $this->crearJuegoConPlugin(
            ['premios' => ['base' => 50, 'modalidades' => [], 'comodines' => []]],
            Animalitos::class
        );
        JuegoOpcion::create([
            'juego_id' => $juego->id,
            'label' => 'Jaguar',
            'value' => 'jaguar',
            'numero' => 77,
            'active' => true,
            'sort_order' => 1,
        ]);

        $valido = app(JuegoPluginManager::class)->validarApuesta(
            $juego,
            ['combinacion' => ['animal' => 'Delfín']]
        );

        $this->assertFalse($valido, 'El zoo propio sustituye al mapa canónico');
    }

    public function test_validar_apuesta_fallback_a_opciones_del_plugin_sin_zoo_propio()
    {
        // Sin filas en juego_opciones: el manager usa obtenerOpciones() del
        // plugin (canónico) — mismo contrato que createApuesta (REQ15).
        $juego = $this->crearJuegoConPlugin(
            ['premios' => ['base' => 30, 'modalidades' => [], 'comodines' => []]],
            Animalitos::class
        );

        $manager = app(JuegoPluginManager::class);
        $this->assertTrue($manager->validarApuesta($juego, ['combinacion' => ['animal' => 'Delfín']]));
        $this->assertFalse($manager->validarApuesta($juego, ['combinacion' => ['animal' => 'dragon']]));
    }

    public function test_validar_apuesta_rechaza_sin_plugin()
    {
        $juego = Juego::create([
            'name' => 'Sin Plugin',
            'slug' => 'sin-plugin',
            'type' => 'animalitos',
            'config' => ['premios' => ['base' => 30]],
            'requires_scraper' => true,
            'active' => true,
        ]);

        $valido = app(JuegoPluginManager::class)->validarApuesta(
            $juego,
            ['combinacion' => ['animal' => 'Delfín']]
        );

        $this->assertFalse($valido);
    }
}
