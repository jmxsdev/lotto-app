<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Juego;
use App\Models\JuegoHorario;
use App\Models\PluginJuego;
use App\Plugins\Juegos\Terminales;

class TerminalesSeeder extends Seeder
{
    public function run(): void
    {
        $juego = Juego::firstOrCreate(
            ['slug' => 'terminal-activo'],
            [
                'name' => 'Terminal Activo',
                'type' => 'terminales',
                'config' => ['premio_multiplo' => 20],
                'costo_minimo' => 3600,
                'requires_scraper' => true,
                'scraper_url' => 'https://www.lottoactivo.com/resultados/terminal_activo/',
                'active' => true,
            ]
        );

        PluginJuego::firstOrCreate(
            ['juego_id' => $juego->id],
            [
                'class_namespace' => Terminales::class,
                'version' => '1.0.0',
                'active' => true,
            ]
        );

        foreach (range(8, 19) as $h) {
            $hora = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';
            JuegoHorario::firstOrCreate(
                ['juego_id' => $juego->id, 'hora' => $hora],
                ['active' => true]
            );
        }

        $this->command->info('Juego Terminal Activo creado con 12 horarios (sin opciones seedeadas — plugin da fallback).');
    }
}
