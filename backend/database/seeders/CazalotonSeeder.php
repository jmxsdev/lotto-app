<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\LoteriaDeHoyScraper;
use App\Support\PremiosOficiales;
use Illuminate\Database\Seeder;

class CazalotonSeeder extends Seeder
{
    public function run(): void
    {
        // Fuente: loteriadehoy.com (SE MANTIENE — verificado en el WU f24).
        // cazaloton.com NO publica resultados: sus enlaces "Resultados" apuntan
        // a loteriadehoy.com. El reglamento oficial de cazaloton.com
        // (Reglamento.pdf, 17 páginas, parseable) confirma: 38 figuras (0/00/1-36),
        // 11 sorteos 09:00–19:00 y premios CAZALOTÓN 30x (Art. 22) y TRIPLETA
        // 200x (Art. 24). La DUPLETA 800x (Art. 23) queda FUERA de alcance
        // (decisión del cliente): cada jugada es una apuesta independiente y no
        // se configura. `updateOrCreate` aplica las modalidades del reglamento
        // sobre el juego ya registrado.
        $juego = Juego::updateOrCreate(
            ['slug' => 'cazaloton'],
            [
                'name' => 'Cazaloton',
                'type' => 'animalitos',
                'config' => PremiosOficiales::configPara('cazaloton'),
                'requires_scraper' => true,
                'scraper_url' => 'https://loteriadehoy.com/animalito/cazaloton/resultados/',
                'scraper_class' => LoteriaDeHoyScraper::class,
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
                'class_namespace' => Animalitos::class,
                'version' => '1.0.0',
                'active' => true,
            ]
        );

        foreach (range(9, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Cazaloton actualizado (type: animalitos, scraper: LoteriaDeHoyScraper, fuente: loteriadehoy — oficial sin resultados; reglamento verificado: 30x/tripleta 200x, dupleta fuera de alcance).');
    }
}
