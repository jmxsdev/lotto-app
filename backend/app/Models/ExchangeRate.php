<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'rate', 'base_currency', 'reference_date', 'set_by',
        'notes', 'is_active'
    ];

    protected $casts = [
        'rate' => 'float',
        'reference_date' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function setBy()
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
