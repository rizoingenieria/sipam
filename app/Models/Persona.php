<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Persona extends Model
{
    protected $table = 'personas';

    protected $fillable = [
        'nombre', 'primer_apellido', 'segundo_apellido', 'curp', 'fecha_nacimiento',
        'domicilio', 'telefono', 'correo',
    ];

    protected function casts(): array
    {
        return ['fecha_nacimiento' => 'date'];
    }

    protected static function booted(): void
    {
        // La CURP siempre en mayúsculas y sin espacios; vacía se guarda como NULL.
        static::saving(function (Persona $persona): void {
            $curp = strtoupper(trim((string) $persona->curp));
            $persona->curp = $curp === '' ? null : $curp;
        });
    }

    public function titularidades(): HasMany
    {
        return $this->hasMany(Titularidad::class);
    }

    public function titulos(): BelongsToMany
    {
        return $this->belongsToMany(Titulo::class, 'titularidades')
            ->using(Titularidad::class)
            ->withPivot(['rol', 'orden'])
            ->withTimestamps();
    }
}
