<?php

namespace App\Models;

use App\Enums\EstadoInhumado;
use App\Enums\Permiso;
use App\Enums\Sexo;
use App\Models\Concerns\TieneAcervo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inhumado extends Model
{
    use TieneAcervo;

    protected $table = 'inhumados';

    protected $fillable = [
        'espacio_id', 'nombre', 'primer_apellido', 'segundo_apellido', 'es_desconocido', 'sexo',
        'fecha_nacimiento', 'fecha_defuncion', 'edad_al_fallecer', 'numero_acta_defuncion',
        'lugar_fallecimiento', 'causa_fallecimiento', 'fecha_inhumacion', 'estado', 'observaciones',
    ];

    /**
     * La causa de fallecimiento es dato personal sensible: va cifrada en la base de datos y
     * fuera de toArray()/JSON. Quien tenga permiso la lee de forma explícita ($inhumado->causa_fallecimiento).
     */
    protected $hidden = ['causa_fallecimiento'];

    protected function casts(): array
    {
        return [
            'es_desconocido' => 'boolean',
            'sexo' => Sexo::class,
            'estado' => EstadoInhumado::class,
            'fecha_nacimiento' => 'date',
            'fecha_defuncion' => 'date',
            'fecha_inhumacion' => 'date',
            'edad_al_fallecer' => 'integer',
            'causa_fallecimiento' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // Si el acta trae ambas fechas y no se capturó la edad, se calcula.
        static::saving(function (Inhumado $inhumado): void {
            if ($inhumado->edad_al_fallecer === null && $inhumado->fecha_nacimiento && $inhumado->fecha_defuncion) {
                $inhumado->edad_al_fallecer = (int) $inhumado->fecha_nacimiento->diffInYears($inhumado->fecha_defuncion);
            }
        });
    }

    /**
     * La causa de fallecimiento sólo para quien tiene el permiso; para los demás, null.
     * La interfaz y los reportes deben leerla siempre por aquí, nunca del atributo directo.
     */
    public function causaVisiblePara(?User $usuario): ?string
    {
        return $usuario?->puede(Permiso::InhumadosVerCausa) ? $this->causa_fallecimiento : null;
    }

    public function espacio(): BelongsTo
    {
        return $this->belongsTo(Espacio::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class)->orderBy('fecha_hora');
    }

    /** Años completos sepultado a una fecha; null si no se conoce la fecha de inhumación. */
    public function aniosSepultadoA(?\DateTimeInterface $fecha = null): ?int
    {
        return $this->fecha_inhumacion
            ? (int) $this->fecha_inhumacion->diffInYears($fecha ?? now())
            : null;
    }
}
