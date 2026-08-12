<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega la ventana de eliminación de tickets (en minutos) a las agencias.
     * Solo aplica a taquillas; bancas y grupos no tienen este campo.
     */
    public function up(): void
    {
        Schema::table('taquillas', function (Blueprint $table) {
            $table->unsignedInteger('tiempo_eliminacion')
                ->default(5)
                ->after('vigencia_premios')
                ->comment('minutos; ventana para eliminar tickets antes del sorteo');
        });
    }

    public function down(): void
    {
        Schema::table('taquillas', function (Blueprint $table) {
            $table->dropColumn('tiempo_eliminacion');
        });
    }
};
