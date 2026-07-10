<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Configuracion extends Model
{
    use HasFactory;

    protected $fillable = [
        'key', 'value', 'description', 'banca_id'
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function banca()
    {
        return $this->belongsTo(Banca::class);
    }
}
