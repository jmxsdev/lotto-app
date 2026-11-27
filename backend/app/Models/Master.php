<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Master extends Model
{
    use HasFactory;

    protected $fillable = [
        'super_master_id',
        'name',
        'code',
        'active',
        'created_by',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function superMaster(): BelongsTo
    {
        return $this->belongsTo(SuperMaster::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function operador(): MorphOne
    {
        return $this->morphOne(Operador::class, 'entidad', 'entidad_tipo', 'entidad_id');
    }
}
