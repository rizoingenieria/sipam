<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Seccion extends Model
{
    protected $table = 'secciones';

    protected $fillable = ['panteon_id', 'clave', 'nombre', 'observaciones'];

    public function panteon(): BelongsTo
    {
        return $this->belongsTo(Panteon::class);
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }
}
