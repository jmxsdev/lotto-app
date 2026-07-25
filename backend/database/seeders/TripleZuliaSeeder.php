<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Juego;
use App\Models\JuegoOpcion;
use App\Models\JuegoHorario;
use App\Models\PluginJuego;
use App\Plugins\Juegos\TripleZulia;

class TripleZuliaSeeder extends Seeder
{
    protected array $signos = [
        ['label' => 'Aries', 'value' => 'ARI', 'metadata' => ['numero' => 1]],
        ['label' => 'Tauro', 'value' => 'TAU', 'metadata' => ['numero' => 2]],
        ['label' => 'Géminis', 'value' => 'GEM', 'metadata' => ['numero' => 3]],
        ['label' => 'Cáncer', 'value' => 'CAN', 'metadata' => ['numero' => 4]],
        ['label' => 'Leo', 'value' => 'LEO', 'metadata' => ['numero' => 5]],
        ['label' => 'Virgo', 'value' => 'VIR', 'metadata' => ['numero' => 6]],
        ['label' => 'Libra', 'value' => 'LIB', 'metadata' => ['numero' => 7]],
        ['label' => 'Escorpio', 'value' => 'ESC', 'metadata' => ['numero' => 8]],
        ['label' => 'Sagitario', 'value' => 'SAG', 'metadata' => ['numero' => 9]],
        ['label' => 'Capricornio', 'value' => 'CAP', 'metadata' => ['numero' => 10]],
        ['label' => 'Acuario', 'value' => 'ACU', 'metadata' => ['numero' => 11]],
        ['label' => 'Piscis', 'value' => 'PIS', 'metadata' => ['numero' => 12]],
    ];

    protected array $horarios = [
        '12:45', '16:45', '19:05',
    ];

    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'triple-zulia'],
            [
                'name' => 'Triple Zulia',
                'type' => 'triple_zulia',
                'config' => json_encode(['premio_multiplo' => 30]),
                'requires_scraper' => false,
                'scraper_url' => null,
                'active' => true,
            ]
        );

        PluginJuego::firstOrCreate(
            ['juego_id' => $juego->id],
            [
                'class_namespace' => TripleZulia::class,
                'version' => '1.0.0',
                'active' => true,
            ]
        );

        foreach ($this->signos as $i => $signo) {
            JuegoOpcion::firstOrCreate(
                ['juego_id' => $juego->id, 'value' => $signo['value']],
                [
                    'label' => $signo['label'],
                    'numero' => null,
                    'metadata' => $signo['metadata'],
                    'sort_order' => $i,
                    'active' => true,
                ]
            );
        }

        foreach ($this->horarios as $hora) {
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info("Juego Triple Zulia creado con " . count($this->signos) . " signos y " . count($this->horarios) . " horarios.");
    }
}
