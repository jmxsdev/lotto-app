<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use Illuminate\Database\Seeder;

class JuegoAnimalitosSeeder extends Seeder
{
    protected array $animales = [
        ['label' => 'Ballena', 'value' => 'ballena', 'numero' => 0],
        ['label' => 'Delfín', 'value' => 'delfin', 'numero' => 0],
        ['label' => 'Carnero', 'value' => 'carnero', 'numero' => 1],
        ['label' => 'Toro', 'value' => 'toro', 'numero' => 2],
        ['label' => 'Ciempiés', 'value' => 'ciempies', 'numero' => 3],
        ['label' => 'Alacrán', 'value' => 'alacran', 'numero' => 4],
        ['label' => 'León', 'value' => 'leon', 'numero' => 5],
        ['label' => 'Rana', 'value' => 'rana', 'numero' => 6],
        ['label' => 'Perico', 'value' => 'perico', 'numero' => 7],
        ['label' => 'Ratón', 'value' => 'raton', 'numero' => 8],
        ['label' => 'Águila', 'value' => 'aguila', 'numero' => 9],
        ['label' => 'Tigre', 'value' => 'tigre', 'numero' => 10],
        ['label' => 'Gato', 'value' => 'gato', 'numero' => 11],
        ['label' => 'Caballo', 'value' => 'caballo', 'numero' => 12],
        ['label' => 'Mono', 'value' => 'mono', 'numero' => 13],
        ['label' => 'Paloma', 'value' => 'paloma', 'numero' => 14],
        ['label' => 'Zorro', 'value' => 'zorro', 'numero' => 15],
        ['label' => 'Oso', 'value' => 'oso', 'numero' => 16],
        ['label' => 'Pavo', 'value' => 'pavo', 'numero' => 17],
        ['label' => 'Burro', 'value' => 'burro', 'numero' => 18],
        ['label' => 'Chivo', 'value' => 'chivo', 'numero' => 19],
        ['label' => 'Cochino', 'value' => 'cochino', 'numero' => 20],
        ['label' => 'Gallo', 'value' => 'gallo', 'numero' => 21],
        ['label' => 'Camello', 'value' => 'camello', 'numero' => 22],
        ['label' => 'Cobra', 'value' => 'cobra', 'numero' => 23],
        ['label' => 'Iguana', 'value' => 'iguana', 'numero' => 24],
        ['label' => 'Gallina', 'value' => 'gallina', 'numero' => 25],
        ['label' => 'Vaca', 'value' => 'vaca', 'numero' => 26],
        ['label' => 'Perro', 'value' => 'perro', 'numero' => 27],
        ['label' => 'Zamuro', 'value' => 'zamuro', 'numero' => 28],
        ['label' => 'Elefante', 'value' => 'elefante', 'numero' => 29],
        ['label' => 'Caimán', 'value' => 'caiman', 'numero' => 30],
        ['label' => 'Lapa', 'value' => 'lapa', 'numero' => 31],
        ['label' => 'Ardilla', 'value' => 'ardilla', 'numero' => 32],
        ['label' => 'Pescado', 'value' => 'pescado', 'numero' => 33],
        ['label' => 'Venado', 'value' => 'venado', 'numero' => 34],
        ['label' => 'Jirafa', 'value' => 'jirafa', 'numero' => 35],
        ['label' => 'Culebra', 'value' => 'culebra', 'numero' => 36],
    ];

    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'lotto-activo'],
            [
                'name' => 'Lotto Activo',
                'type' => 'animalitos',
                'config' => ['premio_multiplo' => 30],
                'requires_scraper' => true,
                'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
                'active' => true,
            ]
        );

        // Insertar límite mínimo por defecto a nivel banca (moneda BS)
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
                [
                    'limite_minimo' => 3600,
                ]
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

        foreach ($this->animales as $i => $animal) {
            JuegoOpcion::firstOrCreate(
                ['juego_id' => $juego->id, 'value' => $animal['value']],
                [
                    'label' => $animal['label'],
                    'numero' => $animal['numero'],
                    'sort_order' => $i,
                    'active' => true,
                ]
            );
        }

        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Lotto Activo creado con '.count($this->animales).' animales y 12 horarios.');
    }
}
