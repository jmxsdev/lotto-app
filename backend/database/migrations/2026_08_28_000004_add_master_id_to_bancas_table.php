<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bancas', function (Blueprint $table) {
            $table->foreignId('master_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->index('master_id');
        });
    }

    public function down(): void
    {
        Schema::table('bancas', function (Blueprint $table) {
            $table->dropForeign(['master_id']);
            $table->dropIndex(['master_id']);
            $table->dropColumn('master_id');
        });
    }
};
