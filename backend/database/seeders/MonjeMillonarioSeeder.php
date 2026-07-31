<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;

class MonjeMillonarioSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::updateOrCreate(
            ['slug' => 'monje-millonario'],
            [
                'name' => 'Monje Millonario',
                'type' => 'animalitos',
                'config' => ['premio_multiplo' => 30],
                'costo_minimo' => 3600,
                'requires_scraper' => true,
                'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
                'active' => true,
            ]
        );

        PluginJuego::firstOrCreate(
            ['juego_id' => $juego->id],
            [
                'class_namespace' => Animalitos::class,
                'version' => '1.0.0',
                'active' => true,
            ]
        );

        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT) . ':05';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Monje Millonario creado con 12 horarios (08:05-19:05).');
    }
}
