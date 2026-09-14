<?php

namespace Database\Seeders;

use App\Models\Banca;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\JuegoLimite;
use App\Models\JuegoOpcion;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Animalitos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MonjeMillonarioSeeder extends Seeder
{
    /**
     * Zoológico PROPIO de Monje Millonario ("Lotto Activo 2"), 70 figuras
     * CONFIRMADAS con la fuente oficial (feed lottoactivo.com muestreado
     * 2026-09-02..14 — 66 resultados, números 0–74 — y los nombres canónicos
     * de la familia para 0–36).
     *
     * - 0–36: mismo zoológico canónico de la familia Lotto Activo (38 etiquetas
     *   con Ballena y Delfín en 0; 23 = Cebra según el feed oficial y el
     *   reglamento Ruleta Royal).
     * - 37–75: figuras nuevas observadas en el feed (p. ej. 49 = Pereza,
     *   42 = Tucán, 74 = Turpial). Los números 16/22/25/34 no salieron en la
     *   muestra de Monje pero se completan con el nombre canónico de la familia
     *   (evidencia: aparecen en los feeds de Lotto Activo/RD con esos nombres).
     *
     * @var array<int, array{0: int, 1: string}> pares [numero, nombre]
     */
    protected array $zoo = [
        [0, 'Delfín'], [0, 'Ballena'], [1, 'Carnero'], [2, 'Toro'], [3, 'Ciempiés'],
        [4, 'Alacrán'], [5, 'León'], [6, 'Rana'], [7, 'Perico'], [8, 'Ratón'],
        [9, 'Águila'], [10, 'Tigre'], [11, 'Gato'], [12, 'Caballo'], [13, 'Mono'],
        [14, 'Paloma'], [15, 'Zorro'], [16, 'Oso'], [17, 'Pavo'], [18, 'Burro'],
        [19, 'Chivo'], [20, 'Cochino'], [21, 'Gallo'], [22, 'Camello'], [23, 'Cebra'],
        [24, 'Iguana'], [25, 'Gallina'], [26, 'Vaca'], [27, 'Perro'], [28, 'Zamuro'],
        [29, 'Elefante'], [30, 'Caimán'], [31, 'Lapa'], [32, 'Ardilla'], [33, 'Pescado'],
        [34, 'Venado'], [35, 'Jirafa'], [36, 'Culebra'],
        [38, 'Búfalo'], [40, 'Avispa'], [41, 'Canguro'], [42, 'Tucán'], [43, 'Mariposa'],
        [44, 'Chigüire'], [45, 'Garza'], [46, 'Puma'], [47, 'Pavo Real'], [48, 'Puercoespín'],
        [49, 'Pereza'], [50, 'Canario'], [51, 'Pelícano'], [52, 'Pulpo'], [53, 'Caracol'],
        [54, 'Grillo'], [55, 'Oso Hormiguero'], [56, 'Tiburón'], [58, 'Hormiga'],
        [59, 'Pantera'], [60, 'Camaleón'], [61, 'Panda'], [62, 'Cachicamo'],
        [63, 'Cangrejo'], [64, 'Gavilán'], [66, 'Lobo'], [69, 'Conejo'], [70, 'Bisonte'],
        [71, 'Guacamaya'], [72, 'Gorila'], [73, 'Hipopótamo'], [74, 'Turpial'],
    ];

    /**
     * Números del zoológico SIN nombre confirmado en la fuente oficial
     * (nunca salieron en la muestra de 66 resultados): 37, 39, 57, 65, 67, 68
     * y 75. El 75 podría ser la figura especial "El Patronus" (la informativa
     * declara 77 figuras: 76 animales + Patronus), pero NO hay confirmación
     * oficial → quedan PENDIENTES (ver docs/seguimiento-verificacion.md).
     *
     * @var array<int, int>
     */
    protected array $pendientes = [37, 39, 57, 65, 67, 68, 75];

    public function run(): void
    {
        $juego = Juego::updateOrCreate(
            ['slug' => 'monje-millonario'],
            [
                'name' => 'Monje Millonario',
                'type' => 'animalitos',
                'config' => ['premio_multiplo' => 30],
                'requires_scraper' => true,
                'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
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

        // Reemplazo determinista: el zoológico de este juego vive en la tabla
        // (antes caía al plugin de 38 figuras). Se borran las filas previas y
        // se siembra el zoológico confirmado (patrón Loto Chaima: value = slug
        // sin acentos, sort_order por posición; Delfín y Ballena comparten 0).
        JuegoOpcion::where('juego_id', $juego->id)->delete();

        $i = 0;
        foreach ($this->zoo as [$numero, $nombre]) {
            JuegoOpcion::firstOrCreate(
                ['juego_id' => $juego->id, 'value' => Str::slug($nombre)],
                [
                    'label' => $nombre,
                    'numero' => (int) $numero,
                    'sort_order' => $i,
                    'active' => true,
                ]
            );
            $i++;
        }

        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT).':05';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Monje Millonario actualizado: zoológico propio de '.count($this->zoo).' figuras confirmadas ('.count($this->pendientes).' números sin nombre oficial pendientes: '.implode(', ', $this->pendientes).').');
    }
}
