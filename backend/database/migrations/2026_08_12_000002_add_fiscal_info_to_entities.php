<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega los campos fiscales opcionales a bancas, grupos y agencias.
     * Todas las columnas son nullable: una entidad sin datos fiscales
     * funciona con normalidad.
     */
    public function up(): void
    {
        foreach (['bancas', 'grupos', 'taquillas'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->string('rif')->nullable();
                $table->string('email')->nullable();
                $table->string('telefono')->nullable();
                $table->string('direccion')->nullable();
                $table->string('estado')->nullable();
                $table->string('municipio')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['bancas', 'grupos', 'taquillas'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn(['rif', 'email', 'telefono', 'direccion', 'estado', 'municipio']);
            });
        }
    }
};
