<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\GuacharoActivoOficialScraper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GuacharoActivoSeeder extends Seeder
{
    public function run(): void
    {
        // Fuente OFICIAL: guacharoactivo.com.ve (SPA) → API lotterly.co (migrado
        // desde loteriadehoy en el WU f24). Premios OFICIALES del bundle oficial
        // del sitio (index-Dv-KFMIs.js): animalito regular 60x y comodín
        // Guácharo (75) = 120x (duplica el premio). `updateOrCreate` aplica la
        // migración de fuente sobre el juego ya registrado.
        $juego = Juego::updateOrCreate(
            ['slug' => 'guacharo-activo'],
            [
                'name' => 'Guacharo Activo',
                'type' => 'animalitos',
                'config' => [
                    'premio_multiplo' => 60,
                    'comodines' => [
                        'guacharo-75' => [
                            'nombre' => 'Guácharo',
                            'numero' => 75,
                            'premio_multiplo' => 120,
                        ],
                    ],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://api.lotterly.co/v1/results/guacharo-activo/',
                'scraper_class' => GuacharoActivoOficialScraper::class,
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

        // Zoológico PROPIO de 77 figuras (00 Ballena + 0 Delfín + 01..75
        // Guacharo): el mapa vive en el scraper (fuente única de nombres y de
        // opciones, patrón Loto Chaima). value = slug sin acentos (Str::slug);
        // sort_order determinista = orden del mapa.
        $i = 0;
        foreach (GuacharoActivoOficialScraper::ZOOLOGICO as $clave => $nombre) {
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

        $this->command->info('Juego Guacharo Activo actualizado (type: animalitos, scraper: GuacharoActivoOficialScraper, fuente: API oficial lotterly.co, '.count(GuacharoActivoOficialScraper::ZOOLOGICO).' figuras, premio 60x + comodín 75 120x).');
    }
}
