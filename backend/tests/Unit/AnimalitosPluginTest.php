<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Plugins\Juegos\Animalitos;

class AnimalitosPluginTest extends TestCase
{
    protected $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = new Animalitos();
    }

    public function test_validar_apuesta_correcta()
    {
        $data = ['combinacion' => ['animal' => 'perro']];
        $this->assertTrue($this->plugin->validarApuesta($data));
    }

    public function test_validar_apuesta_incorrecta()
    {
        $data = ['combinacion' => ['animal' => 'dragon']];
        $this->assertFalse($this->plugin->validarApuesta($data));
    }

    public function test_calcular_premio_ganador()
    {
        $apuesta = ['combinacion' => ['animal' => 'perro'], 'monto' => 100];
        $resultados = ['nombre_animal' => 'perro'];
        $premio = $this->plugin->calcularPremio($apuesta, $resultados);
        $this->assertEquals(3000, $premio); // 100 * 30 = 3000
    }

    public function test_calcular_premio_perdedor()
    {
        $apuesta = ['combinacion' => ['animal' => 'perro'], 'monto' => 100];
        $resultados = ['animal_ganador' => 'gato'];
        $premio = $this->plugin->calcularPremio($apuesta, $resultados);
        $this->assertEquals(0, $premio);
    }

    public function test_obtener_reglas()
    {
        $reglas = $this->plugin->obtenerReglas();
        $this->assertArrayHasKey('descripcion', $reglas);
        $this->assertArrayHasKey('animales_disponibles', $reglas);
    }
}
