<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Roles: super_master, master, banca, grupo, taquilla
            $table->string('role')->default('taquilla');
            $table->foreignId('banca_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('grupo_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('taquilla_id')->nullable()->constrained()->onDelete('set null');
            $table->boolean('active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'banca_id', 'grupo_id', 'taquilla_id', 'active']);
        });
    }
};
