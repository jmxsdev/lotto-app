<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\LaGranjitaScraper;
use Illuminate\Database\Seeder;

class LaGranjitaSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'la-granjita'],
            [
                'name' => 'La Granjita',
                'type' => 'animalitos',
                'config' => [
                    'premio_multiplo' => 30,
                    'scraper' => ['product_id' => '1'],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://www.lagranjita.com/api/results.json?productId=1',
                'scraper_class' => LaGranjitaScraper::class,
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

        // Horarios 08:00–19:00 (:00 cada hora, 12 sorteos al día).
        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego La Granjita actualizado (type: animalitos, scraper: LaGranjitaScraper, fuente: API oficial lagranjita.com).');
    }
}
