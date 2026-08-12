<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega la ventana de eliminación de tickets (en minutos) a las tres entidades.
     * Herencia jerárquica: banca → grupo → agencia.
     * NULL en un nivel = hereda del padre; NULL en banca = usa el default del sistema (5).
     * Un nivel hijo solo puede acortar (más restrictivo) el plazo del padre.
     */
    public function up(): void
    {
        Schema::table('bancas', function (Blueprint $table) {
            $table->unsignedInteger('tiempo_eliminacion')
                ->nullable()
                ->after('vigencia_premios')
                ->comment('minutos; ventana para eliminar tickets antes del sorteo; NULL = usa default 5');
        });

        Schema::table('grupos', function (Blueprint $table) {
            $table->unsignedInteger('tiempo_eliminacion')
                ->nullable()
                ->after('vigencia_premios')
                ->comment('minutos; NULL = hereda de la banca');
        });

        Schema::table('taquillas', function (Blueprint $table) {
            $table->unsignedInteger('tiempo_eliminacion')
                ->nullable()
                ->after('vigencia_premios')
                ->comment('minutos; NULL = hereda del grupo');
        });
    }

    public function down(): void
    {
        Schema::table('bancas', function (Blueprint $table) {
            $table->dropColumn('tiempo_eliminacion');
        });

        Schema::table('grupos', function (Blueprint $table) {
            $table->dropColumn('tiempo_eliminacion');
        });

        Schema::table('taquillas', function (Blueprint $table) {
            $table->dropColumn('tiempo_eliminacion');
        });
    }
};
