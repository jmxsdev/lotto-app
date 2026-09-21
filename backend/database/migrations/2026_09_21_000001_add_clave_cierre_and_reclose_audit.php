<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega la clave de cierre por usuario (hash bcrypt nullable) y la
     * auditoría del re-cierre diario sobre el cierre de caja.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('clave_cierre')->nullable()->after('password');
        });

        Schema::table('cierres_caja', function (Blueprint $table) {
            $table->foreignId('reclosed_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reclosed_at')->nullable()->after('reclosed_by');
        });
    }

    public function down(): void
    {
        // MySQL: drop FK antes de la columna; SQLite: rebuild de la tabla (Laravel 13).
        Schema::table('cierres_caja', function (Blueprint $table) {
            $table->dropForeign(['reclosed_by']);
        });

        Schema::table('cierres_caja', function (Blueprint $table) {
            $table->dropColumn(['reclosed_by', 'reclosed_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('clave_cierre');
        });
    }
};
