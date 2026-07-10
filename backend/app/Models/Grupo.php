<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Grupo extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'banca_id', 'active'
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function banca()
    {
        return $this->belongsTo(Banca::class);
    }

    public function taquillas()
    {
        return $this->hasMany(Taquilla::class);
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

