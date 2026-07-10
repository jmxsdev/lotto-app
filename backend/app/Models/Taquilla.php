<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Taquilla extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'grupo_id', 'mac_address', 'activation_code',
        'active', 'last_connection_at'
    ];

    protected $casts = [
        'active' => 'boolean',
        'last_connection_at' => 'datetime',
    ];

    public function grupo()
    {
        return $this->belongsTo(Grupo::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function apuestas()
    {
        return $this->hasMany(Apuesta::class);
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class);
    }

    public function cierresCaja()
    {
        return $this->hasMany(CierreCaja::class);
    }

    public function comisiones()
    {
        return $this->hasMany(Comision::class);
    }
}
