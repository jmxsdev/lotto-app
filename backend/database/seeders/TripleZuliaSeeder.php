<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Juego;
use App\Models\JuegoOpcion;
use App\Models\JuegoHorario;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;

class TripleZuliaSeeder extends Seeder
{
    protected array $signos = [
        'ARI' => 'Aries', 'TAU' => 'Tauro', 'GEM' => 'Géminis', 'CAN' => 'Cáncer',
        'LEO' => 'Leo', 'VIR' => 'Virgo', 'LIB' => 'Libra', 'ESC' => 'Escorpio',
        'SAG' => 'Sagitario', 'CAP' => 'Capricornio', 'ACU' => 'Acuario', 'PIS' => 'Piscis',
    ];

    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'triple-zulia'],
            [
                'name' => 'Triple Zulia',
                'type' => 'tripletas',
                'config' => ['premio_multiplo' => 30],
                'costo_minimo' => 3600,
                'requires_scraper' => true,
                'scraper_url' => 'https://resultadostriplezulia.com/',
                'active' => true,
            ]
        );

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

        foreach (['12:45', '16:45', '19:05'] as $hora) {
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Triple Zulia actualizado (type: tripletas, plugin: Tripletas).');
    }
}
