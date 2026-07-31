<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;

class TrioActivoSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::updateOrCreate(
            ['slug' => 'trio-activo'],
            [
                'name' => 'Trío Activo',
                'type' => 'tripletas',
                'config' => ['premio_multiplo' => 30, 'modalidades_permitidas' => ['triple_a']],
                'costo_minimo' => 3600,
                'requires_scraper' => true,
                'scraper_url' => 'https://www.lottoactivo.com/resultados/trio_activo/',
                'active' => true,
            ]
        );

        PluginJuego::firstOrCreate(
            ['juego_id' => $juego->id],
            [
                'class_namespace' => Tripletas::class,
                'version' => '1.0.0',
                'active' => true,
            ]
        );

        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Trío Activo creado (solo triple_a) con 12 horarios (08:00-19:00).');
    }
}
