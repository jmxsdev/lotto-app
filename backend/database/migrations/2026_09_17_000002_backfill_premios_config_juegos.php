<?php

use App\Support\PremiosOficiales;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de `juegos.config` con el catálogo oficial (D2/D4, REQ1/REQ7).
 *
 * Para cada uno de los 21 juegos escribe, desde `PremiosOficiales` (única
 * fuente de valores):
 *   - `premios` canónico `{base, modalidades, comodines}` (lo lee el motor);
 *   - espejos legacy `premio_multiplo` (= base), `modalidades` y `comodines`
 *     (claves históricas, valores canónicos) que consumen el export y los
 *     tests existentes;
 *   - `active` según el catálogo.
 *
 * `la-ricachona` (REQ7, sin fuente oficial) queda `active=false`, su plugin
 * inactivo y sin `premios`/`premio_multiplo` (no tiene valores que migrar).
 *
 * Idempotente: re-ejecutarla reescribe el mismo config (merge sobre el
 * existente). Reversible: retira `premios` y reactiva ricachona/plugin; los
 * espejos legacy actualizados (p. ej. monje 50) no se restauran (contrato
 * documentado: `down` solo revierte el canon del motor y la desactivación).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (PremiosOficiales::todos() as $slug => $catalogo) {
            $juego = DB::table('juegos')->where('slug', $slug)->first();

            if ($juego === null) {
                continue;
            }

            $config = json_decode($juego->config ?? '{}', true) ?: [];
            $config = array_merge($config, PremiosOficiales::configPara($slug));

            if (! isset($catalogo['base'])) {
                // la-ricachona: sin fuente oficial → sin premios ni multiplicador.
                unset($config['premios'], $config['premio_multiplo']);
            }

            DB::table('juegos')
                ->where('id', $juego->id)
                ->update([
                    'config' => json_encode($config),
                    'active' => $catalogo['active'],
                ]);

            if (! $catalogo['active']) {
                DB::table('plugin_juegos')
                    ->where('juego_id', $juego->id)
                    ->update(['active' => false]);
            }
        }
    }

    public function down(): void
    {
        foreach (PremiosOficiales::todos() as $slug => $catalogo) {
            $juego = DB::table('juegos')->where('slug', $slug)->first();

            if ($juego === null) {
                continue;
            }

            $config = json_decode($juego->config ?? '{}', true) ?: [];
            unset($config['premios']);

            DB::table('juegos')
                ->where('id', $juego->id)
                ->update([
                    'config' => json_encode($config),
                    'active' => true,
                ]);

            DB::table('plugin_juegos')
                ->where('juego_id', $juego->id)
                ->update(['active' => true]);
        }
    }
};
