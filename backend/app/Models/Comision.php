<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comision extends Model
{
    use HasFactory;

    protected $table = 'comisiones';

    protected $fillable = [
        'banca_id', 'grupo_id', 'taquilla_id', 'periodo',
        'monto_comision', 'estado', 'fecha_inicio', 'fecha_fin',
    ];

    protected $casts = [
        'monto_comision' => 'decimal:2',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    public function banca()
    {
        return $this->belongsTo(Banca::class);
    }

    public function grupo()
    {
        return $this->belongsTo(Grupo::class);
    }

    public function taquilla()
    {
        return $this->belongsTo(Taquilla::class);
    }
}
