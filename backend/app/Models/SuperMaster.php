<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class SuperMaster extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'active',
        'created_by',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function masters(): HasMany
    {
        return $this->hasMany(Master::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function operador(): MorphOne
    {
        return $this->morphOne(Operador::class, 'entidad', 'entidad_tipo', 'entidad_id');
    }
}
