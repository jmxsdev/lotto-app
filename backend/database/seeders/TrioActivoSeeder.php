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

class TrioActivoSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::updateOrCreate(
            ['slug' => 'trio-activo'],
            [
                'name' => 'Trío Activo',
                'type' => 'tripletas',
                'config' => [
                    'premio_multiplo' => 600,
                    'modalidades' => [
                        'terminal' => 60,
                        'punta' => 60,
                    ],
                    'modalidades_permitidas' => ['triple_a'],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://www.lottoactivo.com/resultados/trio_activo/',
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

        // Opciones del TERMINAL real (00-99): el reglamento oficial
        // (Trio_Activo.pdf — "TRIOACTIVO EL PATRONUS") define las modalidades
        // TRIPLE (3 cifras 000-999, entrada libre), TERMINAL (2 últimos dígitos
        // del triple) y PUNTA (2 primeros). NO existe zodiaco → las 12 opciones
        // de signos del plugin Tripletas eran incorrectas para este juego.
        // Patrón Triple Fácil (H10): label con padding "00".."99", value sin
        // padding "0".."99", numero 0..99; el triple queda documentado en config.
        foreach (range(0, 99) as $num) {
            JuegoOpcion::firstOrCreate(
                ['juego_id' => $juego->id, 'value' => (string) $num],
                [
                    'label' => str_pad((string) $num, 2, '0', STR_PAD_LEFT),
                    'numero' => $num,
                    'sort_order' => $num,
                    'active' => true,
                ]
            );
        }

        // Horarios 08:00–19:00 (:00 cada hora, 12 sorteos al día — confirmado
        // por el feed oficial 2026-09-10..14; el reglamento de 2020 declara 3
        // sorteos pero la operación real es de 12 — ver docs/seguimiento-verificacion.md).
        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Trío Activo actualizado (type: tripletas, premio TRIPLE 600x del reglamento oficial, 100 opciones de terminal 00-99, 12 horarios 08:00-19:00).');
    }
}
