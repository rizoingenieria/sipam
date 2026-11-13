<?php

namespace App\Models;

use App\Enums\EstadoPanteon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Panteon extends Model
{
    protected $table = 'panteones';

    protected $fillable = [
        'clave', 'nombre', 'domicilio', 'extension_m2', 'capacidad', 'estado', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoPanteon::class,
            'extension_m2' => 'decimal:2',
            'capacidad' => 'integer',
        ];
    }

    public function secciones(): HasMany
    {
        return $this->hasMany(Seccion::class);
    }

    public function lotes(): HasManyThrough
    {
        return $this->hasManyThrough(Lote::class, Seccion::class);
    }
}
