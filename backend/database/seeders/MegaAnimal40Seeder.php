<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\MegaAnimal40Scraper;
use Illuminate\Database\Seeder;

class MegaAnimal40Seeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'mega-animal-40'],
            [
                'name' => 'Mega Animal 40',
                'type' => 'animalitos',
                'config' => [
                    'premio_multiplo' => 30,
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://resultadosvenezuela.com/lottery/mega-animal-40',
                'scraper_class' => MegaAnimal40Scraper::class,
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

        // Zoológico: el CANÓNICO del plugin Animalitos (38 etiquetas, Delfín/Ballena 0,
        // Carnero 1 ... Culebra 36) — coincide con los 38 animalitos del proveedor.
        // SIN filas propias en juego_opciones: el catálogo cae al plugin por fallback.

        // Horarios 09:00–20:00 (:00 cada hora, 12 sorteos al día; bloques Mañana 09-11,
        // Tarde 12-17, Noche 18-20 según el reglamento DIF-RGTO-033-00).
        foreach (range(9, 20) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Mega Animal 40 actualizado (type: animalitos, scraper: MegaAnimal40Scraper, fuente: resultadosvenezuela.com, 12 horarios 09:00–20:00).');
    }
}
