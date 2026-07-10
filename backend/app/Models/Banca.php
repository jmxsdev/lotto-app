<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banca extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'config', 'active'
    ];

    protected $casts = [
        'config' => 'array',
        'active' => 'boolean',
    ];

    // Relaciones
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
}
