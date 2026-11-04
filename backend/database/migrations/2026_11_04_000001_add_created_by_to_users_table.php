<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WU-1a (#84): añade `users.created_by` como metadata de fila (no capability).
 *
 * FK nullable autorreferente: los usuarios existentes quedan con `created_by`
 * `null` (sin backfill), y el borrado del creador no arrastra al creado
 * (`nullOnDelete`). El índice se declara antes que la FK para que MySQL lo
 * reutilice en lugar de crear un segundo índice implícito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('id');
            $table->index('created_by');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropIndex(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
