<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taquillas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignId('grupo_id')->constrained()->onDelete('cascade');
            $table->string('mac_address')->nullable();
            $table->string('activation_code')->unique()->nullable();
            $table->boolean('active')->default(false);
            $table->timestamp('last_connection_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taquillas');
    }
};
