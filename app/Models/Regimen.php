<?php

namespace App\Models;

use App\Models\Concerns\Versionable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Régimen de derecho de uso, versionado por clave (perpetuidad, temporalidad).
 * Reemplaza la versión de la entrega 1: el ámbito de vigencia ahora viene de Versionable.
 */
class Regimen extends Model
{
    use Versionable;

    protected $table = 'regimenes';

    protected array $columnasGeneradas = ['version_abierta'];

    /** Columnas que identifican al mismo registro en todas sus versiones. */
    protected array $llaveVersion = ['clave'];

    /** Corregir la redacción o una errata no cambia ninguna decisión: se permite en vigor. */
    protected array $editablesEnVigor = ['nombre', 'fundamento'];

    protected $fillable = [
        'clave', 'nombre', 'duracion_anios', 'duracion_maxima_anios', 'renovable', 'permite_cesion',
        'fundamento', 'vigente_desde', 'vigente_hasta',
    ];

    protected function casts(): array
    {
        return [
            'duracion_anios' => 'integer',
            'duracion_maxima_anios' => 'integer',
            'renovable' => 'boolean',
            'permite_cesion' => 'boolean',
        ];
    }

    public function titulos(): HasMany
    {
        return $this->hasMany(Titulo::class);
    }

    /** Ya usada: no se puede deshacer, sólo reemplazar con una versión nueva. */
    public function estaEnUso(): bool
    {
        return Titulo::where('regimen_id', $this->id)->exists();
    }
}
