<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\LotoChaimaScraper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LotoChaimaSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'loto-chaima'],
            [
                'name' => 'Loto Chaima',
                'type' => 'animalitos',
                'config' => [
                    'premio_multiplo' => 30,
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://api.lotterly.co/v1/results/loto-chaima/',
                'scraper_class' => LotoChaimaScraper::class,
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

        // Zoológico PROPIO de 57 animales (0–55): el mapa vive en el scraper
        // (fuente única de nombres y de opciones). value = slug sin acentos
        // (Str::slug); sort_order determinista = orden del mapa.
        $i = 0;
        foreach (LotoChaimaScraper::ZOOLOGICO as $clave => $nombre) {
            JuegoOpcion::firstOrCreate(
                ['juego_id' => $juego->id, 'value' => Str::slug($nombre)],
                [
                    'label' => $nombre,
                    'numero' => (int) $clave,
                    'sort_order' => $i,
                    'active' => true,
                ]
            );
            $i++;
        }

        // Horarios 08:00–19:00 (:00 cada hora, 12 sorteos al día).
        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Loto Chaima actualizado (type: animalitos, scraper: LotoChaimaScraper, fuente: API oficial lotterly.co, '.count(LotoChaimaScraper::ZOOLOGICO).' animales).');
    }
}
