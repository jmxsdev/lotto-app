<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\File;
use App\Plugins\Juegos\Animalitos;
use App\Models\Juego;
use App\Models\PluginJuego;

class PluginIntegrationTest extends TestCase
{
    public function test_plugin_detectado_al_crear_archivo()
    {
        // Simular que el archivo existe (en realidad ya existe)
        $path = app_path('Plugins/Juegos/Animalitos.php');
        $this->assertFileExists($path);

        // Cargar plugins manualmente desde el provider
        $plugins = app('plugins');
        $this->assertArrayHasKey(Animalitos::class, $plugins);
    }

    public function test_desactivar_juego_oculta_en_taquilla()
    {
        // Crear un juego con plugin activo
        $juego = Juego::create([
            'name' => 'Animalitos Test',
            'slug' => 'animalitos-test',
            'type' => 'animalitos',
            'active' => true,
        ]);

        $plugin = PluginJuego::create([
            'juego_id' => $juego->id,
            'class_namespace' => Animalitos::class,
            'version' => '1.0.0',
            'active' => true,
        ]);

        // Obtener juegos activos (debe incluir este)
        $juegosActivos = Juego::where('active', true)->get();
        $this->assertContains($juego->id, $juegosActivos->pluck('id'));

        // Desactivar
        $juego->update(['active' => false]);
        $plugin->update(['active' => false]);

        // Volver a consultar juegos activos (no debe aparecer)
        $juegosActivos = Juego::where('active', true)->get();
        $this->assertNotContains($juego->id, $juegosActivos->pluck('id'));
    }
}
