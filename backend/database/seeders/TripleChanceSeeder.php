<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;
use App\Plugins\Scrapers\TripleChanceOficialScraper;
use App\Support\PremiosOficiales;
use Illuminate\Database\Seeder;

class TripleChanceSeeder extends Seeder
{
    protected array $signos = [
        'ARI' => 'Aries', 'TAU' => 'Tauro', 'GEM' => 'Géminis', 'CAN' => 'Cáncer',
        'LEO' => 'Leo', 'VIR' => 'Virgo', 'LIB' => 'Libra', 'ESC' => 'Escorpio',
        'SAG' => 'Sagitario', 'CAP' => 'Capricornio', 'ACU' => 'Acuario', 'PIS' => 'Piscis',
    ];

    public function run(): void
    {
        // Fuente OFICIAL: tuchance.com.ve ("Chance en línea") → api.scalalot.com
        // (migrado desde loteriadehoy en el WU f24). Premios OFICIALES del
        // reglamento (spec §3.2/H23): TRIPLE A/B/C 600x, TRIPLE A+B 200.000x,
        // SOLO A o B 150x, PUNTA 60x, TERMINAL 60x, CRUZADO 3.000x/10x,
        // TRIPLE C + SIGNO 6.000x, TERMINAL+SIGNO 600x, SIGNO solo 6x.
        // El reglamento oficial existe pero es un PDF escaneado (no parseable);
        // el afiche del sitio declaraba SOLO A/B 100x y C+SIGNO 5.000x (corregido
        // a 150x/6.000x según H23). `updateOrCreate` aplica la migración de
        // fuente sobre el juego ya registrado.
        $juego = Juego::updateOrCreate(
            ['slug' => 'triple-chance'],
            [
                'name' => 'Triple Chance',
                'type' => 'tripletas',
                'config' => PremiosOficiales::configPara('triple-chance'),
                'requires_scraper' => true,
                'scraper_url' => 'https://api.scalalot.com/servicelotteryresults/ServicioResultados.svc/ServicioResultados/ConsultarResultadoSorteo/Q0hBTkNF/',
                'scraper_class' => TripleChanceOficialScraper::class,
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

        foreach (range(9, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Triple Chance actualizado (type: tripletas, scraper: TripleChanceOficialScraper, fuente: API oficial tuchance.com.ve/scalalot, premios oficiales del afiche).');
    }
}
