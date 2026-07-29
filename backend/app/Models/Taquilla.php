<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Taquilla extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'grupo_id', 'mac_address', 'activation_code',
        'active', 'last_connection_at', 'created_by'
    ];

    protected $casts = [
        'active' => 'boolean',
        'last_connection_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

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
