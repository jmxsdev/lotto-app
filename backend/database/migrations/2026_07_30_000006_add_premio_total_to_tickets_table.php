<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->decimal('premio_total_bs', 12, 2)->nullable()->after('total_usd');
            $table->decimal('premio_total_usd', 12, 2)->nullable()->after('premio_total_bs');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['premio_total_bs', 'premio_total_usd']);
        });
    }
};
