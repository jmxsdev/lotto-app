<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ticket_code',
        'taquilla_id',
        'total_bs',
        'total_usd',
        'premio_total_bs',
        'premio_total_usd',
        'estado',
    ];

    protected $casts = [
        'total_bs' => 'decimal:2',
        'total_usd' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($ticket) {
            if (! $ticket->ticket_code) {
                $ticket->ticket_code = strtoupper(Str::random(8));
            }
        });
    }

    public function taquilla()
    {
        return $this->belongsTo(Taquilla::class);
    }

    public function apuestas()
    {
        return $this->hasMany(Apuesta::class);
    }
}
