<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comision extends Model
{
    use HasFactory;

    protected $fillable = [
        'banca_id', 'grupo_id', 'taquilla_id', 'periodo',
        'monto_comision', 'estado'
    ];

    protected $casts = [
        'monto_comision' => 'decimal:2',
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
