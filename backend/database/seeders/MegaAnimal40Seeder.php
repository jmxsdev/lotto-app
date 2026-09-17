<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use App\Plugins\Scrapers\MegaAnimal40OficialScraper;
use Illuminate\Database\Seeder;

class MegaAnimal40Seeder extends Seeder
{
    public function run(): void
    {
        // updateOrCreate (WU f27): MIGRA la fuente del agregador
        // resultadosvenezuela.com (excepción autorizada de f14) al SITIO OFICIAL
        // megaanimal40.com (CONALOT + Big Data Tecnology + Lotería de Cojedes).
        // El scraper del proveedor (MegaAnimal40Scraper) queda como clase
        // durmiente, NO se borra (documentado en backend/docs/juegos.md).
        //
        // Premios OFICIALES de la web (texto "Como jugar"): base 30× por animal
        // y 40× cuando SALE el comodín MEGA (respaldo del reglamento N°
        // DIF-RGTO-033-00, solo referenciado — ver docs/inconsistencias.md).
        // El comodín se CAPTURA en `numeros_ganadores.comodin` (mega:"2"); la
        // liquidación 40× pertenece al ciclo futuro del motor de premios.
        //
        // Horarios/opciones sin cambios: 12 sorteos 09:00–20:00 confirmados por
        // el sitio oficial; zoológico canónico de 38 vía plugin Animalitos.
        // LIMITACIÓN: el endpoint oficial solo sirve el DÍA ACTUAL (ignora
        // fechas, sin histórico funcional) — documentada en el scraper.
        $juego = Juego::updateOrCreate(
            ['slug' => 'mega-animal-40'],
            [
                'name' => 'Mega Animal 40',
                'type' => 'animalitos',
                'config' => [
                    'premio_multiplo' => 30,
                    'comodines' => [
                        'mega' => ['nombre' => 'MEGA', 'premio_multiplo' => 40],
                    ],
                ],
                'requires_scraper' => true,
                'scraper_url' => 'https://megaanimal40.com/',
                'scraper_class' => MegaAnimal40OficialScraper::class,
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

        // Zoológico: el CANÓNICO del plugin Animalitos (38 etiquetas, Delfín/Ballena 0,
        // Carnero 1 ... Culebra 36) — coincide con los 38 animalitos del proveedor.
        // SIN filas propias en juego_opciones: el catálogo cae al plugin por fallback.

        // Horarios 09:00–20:00 (:00 cada hora, 12 sorteos al día; bloques Mañana 09-11,
        // Tarde 12-17, Noche 18-20 según el reglamento DIF-RGTO-033-00).
        foreach (range(9, 20) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Mega Animal 40 actualizado (type: animalitos, scraper: MegaAnimal40OficialScraper, fuente: megaanimal40.com oficial, comodín MEGA 40× capturado, 12 horarios 09:00–20:00).');
    }
}
