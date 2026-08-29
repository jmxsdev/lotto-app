<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

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
        'agencia_id',
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

    /**
     * Bancas que este usuario administra como super banca (master).
     */
    public function bancas()
    {
        return $this->hasMany(Banca::class, 'master_id');
    }

    public function grupo()
    {
        return $this->belongsTo(Grupo::class);
    }

    public function taquilla()
    {
        return $this->belongsTo(Taquilla::class);
    }

    public function agencia()
    {
        return $this->belongsTo(Agencia::class);
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

    /**
     * IDs de las bancas cuyo master es este usuario (super banca).
     *
     * @return Collection<int, int>
     */
    public function masterBancaIds(): Collection
    {
        return Banca::query()
            ->where('master_id', $this->id)
            ->pluck('id');
    }

    /**
     * Closure de alcance master para queries con columna directa de banca
     * (Grupo, Taquilla, User, JuegoLimite → 'banca_id'; Banca → 'id').
     *
     * Lista vacía ⇒ whereRaw('1=0'): el master sin bancas ve NADA, nunca global.
     */
    public function masterBancaScope(string $column = 'banca_id'): \Closure
    {
        $ids = $this->masterBancaIds();

        if ($ids->isEmpty()) {
            return fn ($query) => $query->whereRaw('1=0');
        }

        return fn ($query) => $query->whereIn($column, $ids);
    }

    /**
     * Closure de alcance master para queries que cuelgan de taquillas
     * (Apuesta, Ticket, CierreCaja): acota por la cadena taquilla→grupo→banca.
     *
     * Lista vacía ⇒ whereRaw('1=0'): el master sin bancas ve NADA, nunca global.
     */
    public function masterBancaChainScope(): \Closure
    {
        $ids = $this->masterBancaIds();

        if ($ids->isEmpty()) {
            return fn ($query) => $query->whereRaw('1=0');
        }

        return fn ($query) => $query->whereHas('taquilla.grupo.banca', fn ($b) => $b->whereIn('banca_id', $ids));
    }

    /**
     * Closure de alcance master para queries cuyo primer eslabón es el grupo
     * (Taquilla): acota por la cadena grupo→banca.
     *
     * Lista vacía ⇒ whereRaw('1=0'): el master sin bancas ve NADA, nunca global.
     */
    public function masterBancaGroupScope(string $relation = 'grupo'): \Closure
    {
        $ids = $this->masterBancaIds();

        if ($ids->isEmpty()) {
            return fn ($query) => $query->whereRaw('1=0');
        }

        return fn ($query) => $query->whereHas($relation, fn ($g) => $g->whereIn('banca_id', $ids));
    }

    /**
     * ¿Este usuario (rol master) administra la banca indicada?
     */
    public function masterCanAccessBanca(int $bancaId): bool
    {
        return $this->masterBancaIds()->contains($bancaId);
    }
}
