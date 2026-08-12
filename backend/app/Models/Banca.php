<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Banca extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'config', 'monedas_permitidas', 'vigencia_premios', 'active', 'created_by'
    ];

    protected $casts = [
        'config' => 'array',
        'monedas_permitidas' => 'array',
        'vigencia_premios' => 'integer',
        'active' => 'boolean',
    ];

    // Relaciones
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function grupos()
    {
        return $this->hasMany(Grupo::class);
    }

    public function configuraciones()
    {
        return $this->hasMany(Configuracion::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function comisiones()
    {
        return $this->hasMany(Comision::class);
    }

    public function juegoLimites()
    {
        return $this->hasMany(JuegoLimite::class);
    }
}
