<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
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

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'active' => 'boolean',
    ];
    public function guardName()
    {
        return 'api';
    }

    // Relaciones
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

    public function logs()
    {
        return $this->hasMany(Log::class);
    }

    public function exchangeRates()
    {
        return $this->hasMany(ExchangeRate::class, 'set_by');
    }

    public function pagosAutorizados()
    {
        return $this->hasMany(Pago::class, 'created_by');
    }

    public function cierresCaja()
    {
        return $this->hasMany(CierreCaja::class, 'created_by');
    }
}
