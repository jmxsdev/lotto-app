<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;
use App\Plugins\Scrapers\TripleCalienteOficialScraper;
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
        // updateOrCreate: el juego ya existe desde la integración previa (fuente
        // loteriadehoy.com, bloqueada por Cloudflare); este seeder migra la fuente
        // al API oficial de triplecaliente.com manteniendo slug, type y horarios.
        //
        // Premios OFICIALES del reglamento (WU f26): "Reglamento TRIPLE CALIENTE"
        // publicado en el propio triplecaliente.com (Lotería de Cojedes
        // G-20008572-1, 17 págs parseable; copia en
        // docs/reglamentos/reglamento-triple-caliente.pdf). Art. 19: TRIPLE A/B/C
        // 600×, TERMINAL A/B/C 60×, SIGNO CALIENTE (triple+signo) 6.000× y
        // TERMINAL SIGNO 600×. Antes quedaba el default 30× de los triples.
        // Horarios: la API opera 3 sorteos 13:00/16:30/19:10 (domingos solo 19:10)
        // — el reglamento declara 5 (11:10/13:10/15:10/17:10/19:10); se prioriza la
        // operación real (misma política H15/H16). Desajuste documentado (H18).
        $juego = Juego::updateOrCreate(
            ['slug' => 'triple-caliente'],
            [
                'name' => 'Triple Caliente',
                'type' => 'tripletas',
                'config' => [
                    'premio_multiplo' => 600,
                    'modalidades' => [
                        'cola' => 60,
                        'zodiacal' => 6000,
                        'terminal_zodiacal' => 600,
                    ],
                    'scraper' => ['product_id' => '4'],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://triplecaliente.com/api/gaming/results/product',
                'scraper_class' => TripleCalienteOficialScraper::class,
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

        $this->command->info('Juego Triple Caliente actualizado (type: tripletas, scraper: TripleCalienteOficialScraper, fuente: API oficial).');
    }
}
