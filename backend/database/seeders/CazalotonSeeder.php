<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\LoteriaDeHoyScraper;
use Illuminate\Database\Seeder;

class CazalotonSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'cazaloton'],
            [
                'name' => 'Cazaloton',
                'type' => 'animalitos',
                'config' => ['premio_multiplo' => 30],
                'requires_scraper' => true,
                'scraper_url' => 'https://loteriadehoy.com/animalito/cazaloton/resultados/',
                'scraper_class' => LoteriaDeHoyScraper::class,
                'active' => true,
            ]
        );

        $bancaId = Banca::value('id');
        if ($bancaId) {
            JuegoLimite::firstOrCreate(
                [
                    'juego_id' => $juego->id,
                    'banca_id' => $bancaId,
                    'moneda' => 'bs',
                    'grupo_id' => null,
                    'taquilla_id' => null,
                ],
                ['limite_minimo' => 3600]
            );
        }

        PluginJuego::firstOrCreate(
            ['juego_id' => $juego->id],
            [
                'class_namespace' => Animalitos::class,
                'version' => '1.0.0',
                'active' => true,
            ]
        );

        foreach (range(9, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Cazaloton actualizado (type: animalitos, scraper: LoteriaDeHoyScraper).');
    }
}
