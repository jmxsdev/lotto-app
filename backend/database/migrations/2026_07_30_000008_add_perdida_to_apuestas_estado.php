<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE apuestas MODIFY COLUMN estado ENUM('pendiente','pagada','anulada','perdida') DEFAULT 'pendiente'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE apuestas MODIFY COLUMN estado ENUM('pendiente','pagada','anulada') DEFAULT 'pendiente'");
    }
};
