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
        // (migrado desde loteriadehoy en el WU f24). Premios OFICIALES del afiche
        // oficial del sitio (PDF "FINAL-OK-AFICHE-CHANCE-PARA-IMPRIMIR-CON-QR-PLOTEAR.pdf",
        // texto extraído con pdftotext el 14-sep-2026): TRIPLE A/B/C 600x,
        // TRIPLE A+B 200.000x, SOLO A o B 100x, TERMINAL 60x, TERMINAL A+B 5.000x,
        // TRIPLE C + SIGNO 5.000x, SIGNO solo 6x. El reglamento oficial existe
        // pero es un PDF escaneado (no parseable). `updateOrCreate` aplica la
        // migración de fuente sobre el juego ya registrado.
        $juego = Juego::updateOrCreate(
            ['slug' => 'triple-chance'],
            [
                'name' => 'Triple Chance',
                'type' => 'tripletas',
                'config' => [
                    'premio_multiplo' => 600,
                    'modalidades' => [
                        'triple' => 600,
                        'triple_a_b' => 200000,
                        'triple_a_o_b' => 100,
                        'aproximacion' => 10,
                        'terminal' => 60,
                        'terminal_a_b' => 5000,
                        'terminal_a_o_b' => 5,
                        'triple_c_signo' => 5000,
                        'signo' => 6,
                    ],
                ],
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
