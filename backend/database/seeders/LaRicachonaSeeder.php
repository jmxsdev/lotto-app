<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;
use App\Plugins\Scrapers\LaRicachonaScraper;
use Illuminate\Database\Seeder;

class LaRicachonaSeeder extends Seeder
{
    public function run(): void
    {
        // REQ7: la-ricachona NO tiene fuente oficial con multiplicadores → se
        // mantiene registrada (apuestas previas intactas) pero inactiva: no se
        // vende ni se liquida (active=false) y su plugin queda inactivo.
        $juego = Juego::updateOrCreate(
            ['slug' => 'la-ricachona'],
            [
                'name' => 'La Ricachona',
                'type' => 'tripletas',
                'config' => [
                    'modalidades_permitidas' => ['triple_a'],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://laricachona.com/',
                'scraper_class' => LaRicachonaScraper::class,
                'active' => false,
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

        PluginJuego::updateOrCreate(
            ['juego_id' => $juego->id],
            [
                'class_namespace' => Tripletas::class,
                'version' => '1.0.0',
                'active' => false,
            ]
        );

        // Horarios 08:05–19:05 (:05 cada hora, 12 sorteos al día).
        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':05';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego La Ricachona actualizado (type: tripletas, scraper: LaRicachonaScraper, fuente: HTML oficial laricachona.com).');
    }
}
