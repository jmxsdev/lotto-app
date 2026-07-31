<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('juegos')->where('slug', 'terminal-trio')->update([
            'slug' => 'terminal-activo',
            'name' => 'Terminal Activo',
            'requires_scraper' => true,
            'scraper_url' => 'https://www.lottoactivo.com/resultados/terminal_activo/',
        ]);

        // Activar scrapers para juegos animalitos existentes
        DB::table('juegos')->whereIn('slug', [
            'lotto-activo-rd',
            'lotto-activo-rep-dom',
            'monje-millonario',
        ])->update([
            'requires_scraper' => true,
            'scraper_url' => 'https://www.lottoactivo.com/resultados/animalitos/',
        ]);

        // Activar scraper para Trio Activo
        DB::table('juegos')->where('slug', 'trio-activo')->update([
            'requires_scraper' => true,
            'scraper_url' => 'https://www.lottoactivo.com/resultados/trio_activo/',
        ]);
    }

    public function down(): void
    {
        DB::table('juegos')->where('slug', 'terminal-activo')->update([
            'slug' => 'terminal-trio',
            'name' => 'Terminal Trío',
        ]);
    }
};
