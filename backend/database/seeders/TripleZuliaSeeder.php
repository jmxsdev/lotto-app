<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;
use Illuminate\Database\Seeder;

class TripleZuliaSeeder extends Seeder
{
    protected array $signos = [
        'ARI' => 'Aries', 'TAU' => 'Tauro', 'GEM' => 'Géminis', 'CAN' => 'Cáncer',
        'LEO' => 'Leo', 'VIR' => 'Virgo', 'LIB' => 'Libra', 'ESC' => 'Escorpio',
        'SAG' => 'Sagitario', 'CAP' => 'Capricornio', 'ACU' => 'Acuario', 'PIS' => 'Piscis',
    ];

    public function run(): void
    {
        // Premios OFICIALES del reglamento (WU f26): "REGLAMENTO TRIPLE ZULIA
        // NOV2025" publicado en resultadostriplezulia.com (Lotería del Zulia,
        // G-20007649-6, 17 págs parseable; copia en
        // docs/reglamentos/reglamento-triple-zulia.pdf). Art. 19: TRIPLE A/B/C
        // 600×, TERMINAL A/B/C 60×, ZODIACO DEL ZULIA (triple+signo) 6.000× y
        // TERMINAL ZODIACO 600×. Antes quedaba el default 30× de los triples.
        $juego = Juego::updateOrCreate(
            ['slug' => 'triple-zulia'],
            [
                'name' => 'Triple Zulia',
                'type' => 'tripletas',
                'config' => [
                    'premio_multiplo' => 600,
                    'modalidades' => [
                        'cola' => 60,
                        'zodiacal' => 6000,
                        'terminal_zodiacal' => 600,
                    ],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://resultadostriplezulia.com/',
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

        foreach (['12:45', '16:45', '19:05'] as $hora) {
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Triple Zulia actualizado (type: tripletas, plugin: Tripletas).');
    }
}
