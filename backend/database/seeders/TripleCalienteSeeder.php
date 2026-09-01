<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;
use App\Plugins\Scrapers\LoteriaDeHoyScraper;
use Illuminate\Database\Seeder;

class TripleCalienteSeeder extends Seeder
{
    protected array $signos = [
        'ARI' => 'Aries', 'TAU' => 'Tauro', 'GEM' => 'Géminis', 'CAN' => 'Cáncer',
        'LEO' => 'Leo', 'VIR' => 'Virgo', 'LIB' => 'Libra', 'ESC' => 'Escorpio',
        'SAG' => 'Sagitario', 'CAP' => 'Capricornio', 'ACU' => 'Acuario', 'PIS' => 'Piscis',
    ];

    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'triple-caliente'],
            [
                'name' => 'Triple Caliente',
                'type' => 'tripletas',
                'config' => ['premio_multiplo' => 30],
                'requires_scraper' => true,
                'scraper_url' => 'https://loteriadehoy.com/loteria/triplecaliente/resultados/',
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
                'class_namespace' => Tripletas::class,
                'version' => '1.0.0',
                'active' => true,
            ]
        );

        $i = 0;
        foreach ($this->signos as $sigla => $label) {
            JuegoOpcion::firstOrCreate(
                ['juego_id' => $juego->id, 'value' => $sigla],
                [
                    'label' => $label,
                    'numero' => null,
                    'sort_order' => $i,
                    'active' => true,
                ]
            );
            $i++;
        }

        foreach (['13:00', '16:30', '19:10'] as $hora) {
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Triple Caliente actualizado (type: tripletas, scraper: LoteriaDeHoyScraper).');
    }
}
