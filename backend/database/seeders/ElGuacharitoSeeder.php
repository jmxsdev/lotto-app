<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\ElGuacharitoOficialScraper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ElGuacharitoSeeder extends Seeder
{
    public function run(): void
    {
        // Fuente OFICIAL: elguacharitomillonario.com (SPA) → API lotterly.co
        // (migrado desde loteriadehoy en el WU f24). Premios OFICIALES del bundle
        // oficial del sitio (index-EQw1Zdrz.js): animalito regular 70x y figura
        // especial Guacharito (99) = 150x ("el número de la casa").
        // `updateOrCreate` aplica la migración de fuente sobre el juego ya
        // registrado.
        $juego = Juego::updateOrCreate(
            ['slug' => 'el-guacharito'],
            [
                'name' => 'El Guacharito Millonario',
                'type' => 'animalitos',
                'config' => [
                    'premio_multiplo' => 70,
                    'comodines' => [
                        'guacharito-99' => [
                            'nombre' => 'Guacharito',
                            'numero' => 99,
                            'premio_multiplo' => 150,
                        ],
                    ],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://api.lotterly.co/v1/results/el-guacharito-millonario/',
                'scraper_class' => ElGuacharitoOficialScraper::class,
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

        // Zoológico PROPIO de 101 figuras (00 Ballena + 0 Delfin + 01..99
        // Guacharito): el mapa vive en el scraper (fuente única de nombres y de
        // opciones, patrón Loto Chaima). value = slug sin acentos (Str::slug);
        // sort_order determinista = orden del mapa.
        $i = 0;
        foreach (ElGuacharitoOficialScraper::ZOOLOGICO as $clave => $nombre) {
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

        // Horarios 08:30–19:30 (:30 cada hora, 12 sorteos al día).
        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':30';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego El Guacharito Millonario actualizado (type: animalitos, scraper: ElGuacharitoOficialScraper, fuente: API oficial lotterly.co, '.count(ElGuacharitoOficialScraper::ZOOLOGICO).' figuras, premio 70x + especial 99 150x).');
    }
}
