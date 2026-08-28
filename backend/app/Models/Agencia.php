<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agencia extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'grupo_id', 'active', 'created_by',
        'rif', 'email', 'telefono', 'direccion', 'estado', 'municipio',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function grupo()
    {
        return $this->belongsTo(Grupo::class);
    }

    public function taquillas()
    {
        return $this->hasMany(Taquilla::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
