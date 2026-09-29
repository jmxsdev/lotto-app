<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ComisionDefault extends Model
{
    use HasFactory;

    protected $fillable = [
        'moneda',
        'porcentaje_pago',
    ];

    protected $casts = [
        'porcentaje_pago' => 'decimal:2',
    ];
}
