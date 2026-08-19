<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Juego extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'type', 'config', 'requires_scraper',
        'scraper_url', 'active', 'updated_by',
    ];

    protected $casts = [
        'config' => 'array',
        'requires_scraper' => 'boolean',
        'active' => 'boolean',
    ];

    public function updatedByUser()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function auditoria()
    {
        return $this->hasMany(JuegoAuditoria::class, 'juego_id');
    }

    public function pluginJuego()
    {
        return $this->hasOne(PluginJuego::class)->where('active', true);
    }

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

    public function opciones()
    {
        return $this->hasMany(JuegoOpcion::class)->orderBy('sort_order');
    }

    public function horarios()
    {
        return $this->hasMany(JuegoHorario::class)->where('active', true)->orderBy('hora');
    }

    public function juegoLimites()
    {
        return $this->hasMany(JuegoLimite::class);
    }
}
