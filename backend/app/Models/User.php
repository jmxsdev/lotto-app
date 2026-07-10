<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'banca_id',
        'grupo_id',
        'taquilla_id',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    // Relaciones según rol
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

    // Auditoría
    public function logs()
    {
        return $this->hasMany(Log::class);
    }

    // Tasas que ha establecido
    public function exchangeRates()
    {
        return $this->hasMany(ExchangeRate::class, 'set_by');
    }

    // Pagos que ha autorizado
    public function pagosAutorizados()
    {
        return $this->hasMany(Pago::class, 'created_by');
    }

    // Cierres que ha realizado
    public function cierresCaja()
    {
        return $this->hasMany(CierreCaja::class, 'created_by');
    }
}
