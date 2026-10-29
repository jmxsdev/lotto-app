<?php

use App\Support\PremiosOficiales;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de los premios de Triple Táchira (D-02/D-06/D-08, issue #64).
 *
 * - Retira `terminal` (decisión D-02: Táchira no vende posicional).
 * - Hace explícitos `signo_terminal` 500× y `aproximacion` 10×, que antes
 *   dependían del fallback al `base`.
 * - Fija el Par Millonario 200.000× con `solo_a_b` en 0 hasta que aparezca el
 *   texto del reglamento (D-06).
 *
 * Reescribe `premios` y los espejos legacy desde el catálogo (fuente única).
 * Idempotente: re-ejecutarla escribe los mismos valores.
 */
return new class extends Migration
{
    private const SLUG = 'triple-tachira';

    public function up(): void
    {
        $this->aplicar(PremiosOficiales::configPara(self::SLUG));
    }

    public function down(): void
    {
        $this->aplicar([
            'premios' => [
                'base' => 500,
                'modalidades' => ['terminal' => 50, 'signo_triple' => 5000],
                'posicion_triples' => [],
                'comodines' => [],
            ],
            'premio_multiplo' => 500,
            'modalidades' => ['cola' => 50, 'zodiacal' => 5000],
            'comodines' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $parcial  claves de `config` a reemplazar
     */
    private function aplicar(array $parcial): void
    {
        $juego = DB::table('juegos')->where('slug', self::SLUG)->first();

        if ($juego === null) {
            return;
        }

        $config = json_decode($juego->config ?? '{}', true) ?: [];
        $config = array_merge($config, $parcial);

        DB::table('juegos')
            ->where('id', $juego->id)
            ->update(['config' => json_encode($config)]);
    }
};
