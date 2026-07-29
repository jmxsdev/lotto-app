<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taquillas', function (Blueprint $table) {
            $table->string('device_fingerprint')->nullable()->unique()->after('mac_address');
        });
    }

    public function down(): void
    {
        Schema::table('taquillas', function (Blueprint $table) {
            $table->dropColumn('device_fingerprint');
        });
    }
};
