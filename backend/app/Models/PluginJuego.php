<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PluginJuego extends Model
{
    use HasFactory;

    protected $fillable = [
        'juego_id', 'class_namespace', 'version', 'active', 'updated_by'
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function updatedByUser()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function juego()
    {
        return $this->belongsTo(Juego::class);
    }
}
