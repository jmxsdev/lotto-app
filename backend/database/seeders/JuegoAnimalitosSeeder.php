<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Juego;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;

class JuegoAnimalitosSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::firstOrCreate([
            'slug' => 'animalitos',
        ], [
            'name' => 'Animalitos',
            'type' => 'animalitos',
            'config' => json_encode(['premio_multiplo' => 30]),
            'requires_scraper' => true,
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
            'active' => true,
        ]);

        PluginJuego::firstOrCreate([
            'juego_id' => $juego->id,
        ], [
            'class_namespace' => Animalitos::class,
            'version' => '1.0.0',
            'active' => true,
        ]);

        $this->command->info('Juego Animalitos creado con su plugin.');
    }
}
