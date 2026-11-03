<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merge 2 (contract) de `autorizacion-rol-unico`: elimina la columna legacy
 * `users.role` después de que Spatie (`roles`/`model_has_roles`) quedó como
 * única fuente de verdad (Merge 1).
 *
 * Idempotente en ambos sentidos: si el drop ya se aplicó, `up()` no hace nada;
 * si la columna ya fue restaurada, `down()` tampoco. Sin backfill ni lógica de
 * datos: `down()` solo devuelve el esquema, la columna queda vacía (con su
 * default de esquema) y los roles viven en Spatie.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role')->nullable()->default('taquilla');
            });
        }
    }
};
