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
        // Premios: la informativa (resultadosvenezuela.com) declara 600x/60x/6.000x/600x,
        // pero NO hay fuente oficial verificada del premio (los mismos valores que RV
        // declara para Triple Táchira resultaron EQUIVOCADOS — hallazgo H9), así que se
        // usa el default de los triples (30x) y queda documentado como pendiente.
        $juego = Juego::firstOrCreate(
            ['slug' => 'triple-zamorano'],
            [
                'name' => 'Triple Zamorano',
                'type' => 'tripletas',
                'config' => [
                    'premio_multiplo' => 30,
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
