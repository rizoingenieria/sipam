<?php

namespace App\Models;

use App\Enums\EstadoTitulo;
use App\Enums\OrigenTitulo;
use App\Enums\RolTitularidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Título de derechos de uso. En pantalla se dice «título»; jurídicamente otorga sólo el uso (art. 3, fr. XLIV).
 */
class Titulo extends Model
{
    protected $table = 'titulos';

    protected $fillable = [
        'espacio_id', 'regimen_id', 'folio', 'expediente', 'origen', 'estado',
        'fecha_emision', 'fecha_vencimiento', 'titulo_anterior_id', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'origen' => OrigenTitulo::class,
            'estado' => EstadoTitulo::class,
            'fecha_emision' => 'date',
            'fecha_vencimiento' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // El vencimiento se deriva del régimen; no se captura a mano.
        static::saving(function (Titulo $titulo): void {
            if ($titulo->isDirty(['fecha_emision', 'regimen_id']) || $titulo->fecha_vencimiento === null) {
                $regimen = Regimen::findOrFail($titulo->regimen_id);
                $titulo->fecha_vencimiento = $titulo->fecha_emision->copy()->addYearsNoOverflow($regimen->duracion_anios);
            }
        });
    }

    public function espacio(): BelongsTo
    {
        return $this->belongsTo(Espacio::class);
    }

    public function regimen(): BelongsTo
    {
        return $this->belongsTo(Regimen::class);
    }

    public function tituloAnterior(): BelongsTo
    {
        return $this->belongsTo(Titulo::class, 'titulo_anterior_id');
    }

    public function tituloSiguiente(): HasOne
    {
        return $this->hasOne(Titulo::class, 'titulo_anterior_id');
    }

    public function titularidades(): HasMany
    {
        return $this->hasMany(Titularidad::class)->orderBy('orden');
    }

    public function personas(): BelongsToMany
    {
        return $this->belongsToMany(Persona::class, 'titularidades')
            ->using(Titularidad::class)
            ->withPivot(['rol', 'orden'])
            ->withTimestamps();
    }

    /** Titulares y copropietarios: quienes ejercen el derecho y firman anuencias (art. 28). */
    public function titulares(): BelongsToMany
    {
        return $this->personas()->wherePivotIn('rol', [
            RolTitularidad::Titular->value,
            RolTitularidad::Copropietario->value,
        ]);
    }

    public function beneficiarios(): BelongsToMany
    {
        return $this->personas()->wherePivot('rol', RolTitularidad::Beneficiario->value);
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('estado', EstadoTitulo::Vigente->value);
    }

    /** Vigentes cuyo vencimiento cae dentro de los próximos $dias (avisos de renovación, art. 21 fr. XV). */
    public function scopePorVencer(Builder $query, int $dias = 90): Builder
    {
        return $query->vigentes()->whereBetween('fecha_vencimiento', [now()->toDateString(), now()->addDays($dias)->toDateString()]);
    }

    public function estaVencidoA(?\DateTimeInterface $fecha = null): bool
    {
        return $this->fecha_vencimiento->lt($fecha ?? now());
    }
}
