<?php

namespace Database\Seeders;

use App\Models\Juego;
use App\Models\Resultado;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ResultadoTestSeeder extends Seeder
{
    public function run(): void
    {
        $animalitos = Juego::where('slug', 'animalitos')->first();
        $tripleZulia = Juego::where('slug', 'triple-zulia')->first();

        if ($animalitos) {
            Resultado::updateOrCreate(
                [
                    'juego_id' => $animalitos->id,
                    'fecha_sorteo' => Carbon::yesterday()->setTime(19, 0, 0),
                    'hora_sorteo' => '19:00',
                ],
                [
                    'numeros_ganadores' => [
                        'nombre_animal' => 'perro',
                        'imagen_animal' => null,
                        'color_animal' => 'marrón',
                        'pais' => 'VE',
                    ],
                ]
            );
            $this->command->info('Resultado Animalitos (perro) para ayer listo.');
        }

        if ($tripleZulia) {
            Resultado::updateOrCreate(
                [
                    'juego_id' => $tripleZulia->id,
                    'fecha_sorteo' => Carbon::yesterday()->setTime(19, 5, 0),
                    'hora_sorteo' => '19:05',
                ],
                [
                    'numeros_ganadores' => [
                        'triple_a' => '123',
                        'triple_b' => '456',
                        'triple_c' => '789',
                        'signo' => 'LEO',
                        'pais' => 'VE',
                    ],
                ]
            );
            $this->command->info('Resultado Triple Zulia (123, 456, 789, LEO) para ayer listo.');
        }
    }
}
