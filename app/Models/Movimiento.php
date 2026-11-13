<?php

namespace App\Models;

use App\Enums\TipoMovimiento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * Asiento de los libros cronológicos (art. 21, fr. III). Sólo se crea: no se edita ni se borra.
 * Un error se corrige con un movimiento nuevo que lo explique en observaciones.
 */
class Movimiento extends Model
{
    protected $table = 'movimientos';

    protected $fillable = [
        'inhumado_id', 'tramite_id', 'tipo', 'fecha_hora', 'espacio_origen_id', 'espacio_destino_id', 'destino_externo',
        'causa', 'realizado_por', 'solicitante_id', 'permiso_folio', 'permiso_fecha',
        'por_orden_autoridad', 'registrado_por', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimiento::class,
            'fecha_hora' => 'datetime',
            'permiso_fecha' => 'date',
            'por_orden_autoridad' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Movimiento $m): void {
            if ($m->tipo->exigeEspacioOrigen() && $m->espacio_origen_id === null) {
                throw new InvalidArgumentException("Una {$m->tipo->getLabel()} exige el espacio de origen.");
            }
            if ($m->tipo->exigeEspacioDestino() && $m->espacio_destino_id === null) {
                throw new InvalidArgumentException("Una {$m->tipo->getLabel()} exige el espacio de destino.");
            }
            if ($m->tipo === TipoMovimiento::Traslado && blank($m->destino_externo) && $m->espacio_destino_id === null) {
                throw new InvalidArgumentException('Un traslado exige su destino (art. 21, fr. III, inciso c).');
            }
            if ($m->tipo === TipoMovimiento::Exhumacion && blank($m->causa)) {
                throw new InvalidArgumentException('Una exhumación exige su causa (art. 21, fr. III, inciso b).');
            }
        });

        static::updating(fn () => throw new LogicException('Los movimientos no se editan: registre uno nuevo.'));
        static::deleting(fn () => throw new LogicException('Los movimientos no se borran.'));
    }

    public function inhumado(): BelongsTo
    {
        return $this->belongsTo(Inhumado::class);
    }

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class);
    }

    public function espacioOrigen(): BelongsTo
    {
        return $this->belongsTo(Espacio::class, 'espacio_origen_id');
    }

    public function espacioDestino(): BelongsTo
    {
        return $this->belongsTo(Espacio::class, 'espacio_destino_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'solicitante_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
