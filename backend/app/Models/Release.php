<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Release extends Model
{
    use HasFactory;

    protected $fillable = [
        'version',
        'sha256',
        'file_path',
        'file_size',
        'published_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'published_at' => 'datetime',
    ];

    /**
     * Fila única publicada (D3: sin historial; releases:publish reemplaza).
     * La más reciente por published_at; nunca devuelve más de una fila.
     */
    public function scopeCurrent($query)
    {
        return $query->latest('published_at')->limit(1);
    }
}
