<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Tripletas;
use App\Plugins\Scrapers\TripleTachiraScraper;
use Illuminate\Database\Seeder;

class TripleTachiraSeeder extends Seeder
{
    protected array $signos = [
        'ARI' => 'Aries', 'TAU' => 'Tauro', 'GEM' => 'Géminis', 'CAN' => 'Cáncer',
        'LEO' => 'Leo', 'VIR' => 'Virgo', 'LIB' => 'Libra', 'ESC' => 'Escorpio',
        'SAG' => 'Sagitario', 'CAP' => 'Capricornio', 'ACU' => 'Acuario', 'PIS' => 'Piscis',
    ];

    public function run(): void
    {
        // Premios OFICIALES verificados contra el reglamento G-20004065-3 de la
        // Lotería del Táchira (descargado de https://tripletachira.com/docs/reglamento.pdf,
        // texto extraído con pdftotext el 12-sep-2026): A/B 500x, Terminal (cola,
        // 2 últimos dígitos) 50x, Triple+Signo Zodiacal 5.000x.
        // La página informativa (resultadosvenezuela.com) declara 600/60/6.000
        // → desajuste H9 en docs/comparacion-juegos.md; aquí mandan los valores
        // oficiales del reglamento.
        $juego = Juego::firstOrCreate(
            ['slug' => 'triple-tachira'],
            [
                'name' => 'Triple Táchira',
                'type' => 'tripletas',
                'config' => [
                    'premio_multiplo' => 500,
                    'modalidades' => [
                        'cola' => 50,
                        'zodiacal' => 5000,
                    ],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://tripletachira.com/pruebah.php',
                'scraper_class' => TripleTachiraScraper::class,
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

        // Horarios oficiales: 1:15 / 4:45 / 10:10 PM → 13:15, 16:45, 22:10
        // (3 sorteos diarios; el 3er sorteo es 22:10, NO 19:20 como declara la
        // informativa — desajuste H9 documentado en docs/comparacion-juegos.md).
        foreach (['13:15', '16:45', '22:10'] as $hora) {
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Triple Táchira actualizado (type: tripletas, scraper: TripleTachiraScraper, fuente: sitio oficial tripletachira.com, premios oficiales 500/50/5.000).');
    }
}
