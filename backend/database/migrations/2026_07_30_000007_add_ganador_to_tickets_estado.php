<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tickets MODIFY COLUMN estado ENUM('pendiente','pagada','anulada','ganador') DEFAULT 'pendiente'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tickets MODIFY COLUMN estado ENUM('pendiente','pagada','anulada') DEFAULT 'pendiente'");
    }
};
