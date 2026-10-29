<?php

use App\Support\PremiosOficiales;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de `premios.posicion_triples` (D2, issue #64).
 *
 * Los slots posicionales (`a`/`b`/`c`) viajan dentro de `premios` para que el
 * motor, el snapshot de venta y el export del catálogo los compartan sin
 * código extra. El catálogo en código los declara desde
 * `tripletas-auditoria-64`, pero las filas existentes de `juegos.config` no los
 * tienen; sin ellos `Tripletas::slotPosicionalValido` rechaza toda venta
 * posicional.
 *
 * Idempotente: re-ejecutarla reescribe los mismos slots. No toca los juegos sin
 * `premios` (la-ricachona).
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

            if (! isset($config['premios']) || ! is_array($config['premios'])) {
                continue;
            }

            $config['premios']['posicion_triples'] = $catalogo['posicion_triples'] ?? [];

            DB::table('juegos')
                ->where('id', $juego->id)
                ->update(['config' => json_encode($config)]);
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

            if (! isset($config['premios']) || ! is_array($config['premios'])) {
                continue;
            }

            unset($config['premios']['posicion_triples']);

            DB::table('juegos')
                ->where('id', $juego->id)
                ->update(['config' => json_encode($config)]);
        }
    }
};
