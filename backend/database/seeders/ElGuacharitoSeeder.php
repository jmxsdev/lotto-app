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

class ElGuacharitoSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'el-guacharito'],
            [
                'name' => 'El Guacharito Millonario',
                'type' => 'animalitos',
                'config' => ['premio_multiplo' => 30],
                'requires_scraper' => true,
                'scraper_url' => 'https://loteriadehoy.com/animalito/elguacharitomillonario/resultados/',
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

        // Horarios 08:30–19:30 (:30 cada hora, 12 sorteos al día).
        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':30';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego El Guacharito Millonario actualizado (type: animalitos, scraper: LoteriaDeHoyScraper).');
    }
}
