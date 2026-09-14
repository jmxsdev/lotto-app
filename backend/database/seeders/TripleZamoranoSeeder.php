<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;
use App\Plugins\Scrapers\TripleZamoranoScraper;
use Illuminate\Database\Seeder;

class TripleZamoranoSeeder extends Seeder
{
    protected array $signos = [
        'ARI' => 'Aries', 'TAU' => 'Tauro', 'GEM' => 'Géminis', 'CAN' => 'Cáncer',
        'LEO' => 'Leo', 'VIR' => 'Virgo', 'LIB' => 'Libra', 'ESC' => 'Escorpio',
        'SAG' => 'Sagitario', 'CAP' => 'Capricornio', 'ACU' => 'Acuario', 'PIS' => 'Piscis',
    ];

    public function run(): void
    {
        // Premios OFICIALES del reglamento (WU f26): "REGLAMENTO TP ZAMORANO
        // NOV2025" publicado en el propio triplezamorano.com (Lotería del Zulia
        // G-20007649-6, 18 págs parseable; copia en
        // docs/reglamentos/reglamento-triple-zamorano.pdf). Art. 19: TRIPLE 600×,
        // COLA 60×, UÑA 5×, ASTRO (triple+signo) 6.000×, COLA+SIGNO 600× y
        // UÑA+SIGNO 60×. Resuelve H11: la informativa (600/60/6.000/600) era
        // correcta pero SIN fuente verificada; ahora respaldada por el reglamento.
        // Antes quedaba el default 30× de los triples.
        $juego = Juego::updateOrCreate(
            ['slug' => 'triple-zamorano'],
            [
                'name' => 'Triple Zamorano',
                'type' => 'tripletas',
                'config' => [
                    'premio_multiplo' => 600,
                    'modalidades' => [
                        'cola' => 60,
                        'uña' => 5,
                        'zodiacal' => 6000,
                        'cola_signo' => 600,
                        'uña_signo' => 60,
                    ],
                    'scraper' => ['product_id' => '1'],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://www.triplezamorano.com/api/gaming/results/product',
                'scraper_class' => TripleZamoranoScraper::class,
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

        // Horarios oficiales verificados con los timestamps del API (America/Caracas):
        // 5 sorteos diarios 10:00/12:00/14:00/16:00/19:00 (domingos solo 19:00).
        foreach (['10:00', '12:00', '14:00', '16:00', '19:00'] as $hora) {
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Triple Zamorano actualizado (type: tripletas, scraper: TripleZamoranoScraper, fuente: API oficial triplezamorano.com, 12 signos, 5 horarios 10:00-19:00).');
    }
}
