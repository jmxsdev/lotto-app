<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Juego extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'type', 'config', 'requires_scraper',
        'scraper_url', 'active'
    ];

    protected $casts = [
        'config' => 'array',
        'requires_scraper' => 'boolean',
        'active' => 'boolean',
    ];

    public function pluginJuegos()
    {
        return $this->hasMany(PluginJuego::class);
    }

    public function apuestas()
    {
        return $this->hasMany(Apuesta::class);
    }

    public function resultados()
    {
        return $this->hasMany(Resultado::class);
    }
}
