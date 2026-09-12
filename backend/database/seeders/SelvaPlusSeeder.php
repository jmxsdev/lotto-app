<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\SelvaPlusScraper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SelvaPlusSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'selva-plus'],
            [
                'name' => 'Selva Plus',
                'type' => 'animalitos',
                'config' => [
                    'premio_multiplo' => 80,
                    'comodines' => [
                        'comodin-a' => ['nombre' => 'Leoncito', 'premio_multiplo' => 160],
                        'comodin-b' => ['nombre' => 'Selva Plus', 'premio_multiplo' => 200],
                    ],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://api.lotterly.co/v1/results/selva-plus/',
                'scraper_class' => SelvaPlusScraper::class,
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

        // Zoológico PROPIO de 101 figuras (0–99, Ballena y Delfín comparten el
        // numero 0): el mapa vive en el scraper (fuente única de nombres y de
        // opciones, patrón Loto Chaima). value = slug sin acentos (Str::slug);
        // sort_order determinista = orden del mapa.
        $i = 0;
        foreach (SelvaPlusScraper::ZOOLOGICO as $clave => $nombre) {
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

        // Comodines (2): numero null (no son figuras). Sus valores en `result`
        // aún no se observan en el API — el scraper es defensivo (valor crudo).
        $comodines = [
            ['value' => 'comodin-a', 'label' => 'Leoncito (comodín A)'],
            ['value' => 'comodin-b', 'label' => 'Selva Plus (comodín B)'],
        ];

        foreach ($comodines as $comodin) {
            JuegoOpcion::firstOrCreate(
                ['juego_id' => $juego->id, 'value' => $comodin['value']],
                [
                    'label' => $comodin['label'],
                    'numero' => null,
                    'sort_order' => $i,
                    'active' => true,
                ]
            );
            $i++;
        }

        // Horarios 08:15–20:15 (:15 cada hora, 13 sorteos al día).
        foreach (range(8, 20) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':15';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Selva Plus actualizado (type: animalitos, scraper: SelvaPlusScraper, fuente: API oficial lotterly.co, '.count(SelvaPlusScraper::ZOOLOGICO).' figuras + 2 comodines, premio 80x).');
    }
}
