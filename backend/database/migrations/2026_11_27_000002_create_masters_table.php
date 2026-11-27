<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('super_master_id')->constrained('super_masters')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['super_master_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masters');
    }
};
