<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lote extends Model
{
    protected $table = 'lotes';

    protected $fillable = ['seccion_id', 'numero', 'observaciones'];

    public function seccion(): BelongsTo
    {
        return $this->belongsTo(Seccion::class);
    }

    public function espacios(): HasMany
    {
        return $this->hasMany(Espacio::class);
    }
}
