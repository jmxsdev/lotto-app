<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;
use App\Plugins\Scrapers\TripleFacilScraper;
use Illuminate\Database\Seeder;

class TripleFacilSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'triple-facil'],
            [
                'name' => 'Triple Fácil',
                'type' => 'tripletas',
                'config' => [
                    'premio_multiplo' => 700,
                    'modalidades' => [
                        'terminal' => 60,
                        'aproximacion' => 10,
                    ],
                    'modalidades_permitidas' => ['triple_a'],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://api.lotterly.co/v1/results/triple-facil/',
                'scraper_class' => TripleFacilScraper::class,
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

        // Opciones del TERMINAL real (00-99): la web muestra por sorteo
        // prev/main/next donde main es el TRIPLE (3 cifras, entrada libre 000-999)
        // y prev/next son TERMINALES DERIVADAS (los 2 últimos dígitos ±1,
        // calculados en el front — NO son resultados independientes; no existe
        // producto terminal aparte en lotterly: 400 "product_slug does not
        // exist"). El triple es entrada libre y queda documentado en config.
        // label con padding ("00".."99"), value sin padding ("0".."99"),
        // numero 0..99 (mismo patrón que el plugin Terminales).
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
        // por la API oficial: 12 respuestas por fecha).
        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Triple Fácil actualizado (type: tripletas, scraper: TripleFacilScraper, fuente: API oficial lotterly.co, 100 opciones de terminal, 12 horarios 08:00-19:00, premios informativos 700x/60x/10x).');
    }
}
