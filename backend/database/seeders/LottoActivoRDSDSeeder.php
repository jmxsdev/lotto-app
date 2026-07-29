<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;

class LottoActivoRDSDSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'lotto-activo-rep-dom'],
            [
                'name' => 'Lotto Activo República Dominicana',
                'type' => 'animalitos',
                'config' => ['premio_multiplo' => 30],
                'costo_minimo' => 3600,
                'requires_scraper' => false,
                'scraper_url' => null,
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

        foreach (range(8, 21) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Lotto Activo República Dominicana creado con 14 horarios (08:00-21:00).');
    }
}
