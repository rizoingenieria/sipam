<?php

namespace App\Models;

use App\Enums\ComparacionPlazo;
use App\Enums\MotivoExhumacion;
use App\Models\Concerns\Versionable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Regla de plazo mínimo para exhumar. Configurable desde la interfaz y versionada,
 * para que el sistema sobreviva a reformas sin tocar código.
 */
class ReglaPlazo extends Model
{
    use Versionable;

    protected $table = 'reglas_plazo';

    protected array $columnasGeneradas = ['version_abierta'];

    /** Columnas que identifican al mismo registro en todas sus versiones. */
    protected array $llaveVersion = ['clave'];

    /** Corregir la redacción o una errata no cambia ninguna decisión: se permite en vigor. */
    protected array $editablesEnVigor = ['descripcion', 'fundamento'];

    protected $fillable = [
        'clave', 'descripcion', 'motivo', 'regimen_clave', 'edad_minima', 'edad_maxima',
        'solo_panteon_saturado', 'anios_minimos', 'comparacion', 'fundamento', 'vigente_desde', 'vigente_hasta',
    ];

    protected function casts(): array
    {
        return [
            'motivo' => MotivoExhumacion::class,
            'comparacion' => ComparacionPlazo::class,
            'edad_minima' => 'integer',
            'edad_maxima' => 'integer',
            'anios_minimos' => 'integer',
            'solo_panteon_saturado' => 'boolean',
        ];
    }

    public function dependeDeEdad(): bool
    {
        return $this->edad_minima !== null || $this->edad_maxima !== null;
    }

    public function cubreEdad(int $edad): bool
    {
        return ($this->edad_minima === null || $edad >= $this->edad_minima)
            && ($this->edad_maxima === null || $edad <= $this->edad_maxima);
    }

    /** Primer día en que la exhumación cumple esta regla. */
    public function fechaPermitida(CarbonInterface $fechaInhumacion): CarbonInterface
    {
        $aniversario = $fechaInhumacion->copy()->addYearsNoOverflow($this->anios_minimos);

        return $this->comparacion === ComparacionPlazo::MasDe ? $aniversario->addDay() : $aniversario;
    }

    /** Ya usada: no se puede deshacer, sólo reemplazar con una versión nueva. */
    public function estaEnUso(): bool
    {
        return Tramite::where('evaluacion_plazo->regla_plazo_id', $this->id)->exists()
            || Tramite::whereJsonContains('evaluacion_plazo->reglas_aplicadas', $this->id)->exists();
    }
}
