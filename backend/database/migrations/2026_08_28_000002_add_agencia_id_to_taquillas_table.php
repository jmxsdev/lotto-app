<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taquillas', function (Blueprint $table) {
            $table->foreignId('agencia_id')->nullable()->after('grupo_id')->constrained('agencias')->nullOnDelete();
            $table->index('agencia_id');
        });
    }

    public function down(): void
    {
        Schema::table('taquillas', function (Blueprint $table) {
            $table->dropForeign(['agencia_id']);
            $table->dropIndex(['agencia_id']);
            $table->dropColumn('agencia_id');
        });
    }
};
