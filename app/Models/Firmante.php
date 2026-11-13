<?php

namespace App\Models;

use App\Models\Concerns\Versionable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Titular de un cargo en un periodo. El relevo se registra con nuevaVersion(). */
class Firmante extends Model
{
    use Versionable;

    protected $table = 'firmantes';

    protected array $columnasGeneradas = ['version_abierta'];

    /** Columnas que identifican al mismo registro en todas sus versiones. */
    protected array $llaveVersion = ['cargo_id'];

    /** Corregir la redacción o una errata no cambia ninguna decisión: se permite en vigor. */
    protected array $editablesEnVigor = ['titulo_profesional', 'nombre'];

    protected $fillable = ['cargo_id', 'titulo_profesional', 'nombre', 'vigente_desde', 'vigente_hasta'];

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class);
    }

    /** «Lic. Nombre Apellido», como va en el bloque de firma. */
    protected function nombreParaFirma(): Attribute
    {
        return Attribute::get(fn (): string => trim(($this->titulo_profesional ? $this->titulo_profesional.' ' : '').$this->nombre));
    }
}
