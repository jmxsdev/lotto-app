<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bancas', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('active')->constrained('users')->nullOnDelete();
        });

        Schema::table('grupos', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('active')->constrained('users')->nullOnDelete();
        });

        Schema::table('taquillas', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('last_connection_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bancas', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });

        Schema::table('grupos', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });

        Schema::table('taquillas', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
