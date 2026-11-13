<?php

namespace App\Models;

use App\Enums\EstadoOrdenPago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Orden de pago con importe congelado. Una vez emitida, el importe y la cuota no cambian:
 * si el tabulador cambia después, la orden conserva la versión con la que nació.
 */
class OrdenPago extends Model
{
    protected $table = 'ordenes_pago';

    protected $fillable = [
        'folio', 'tramite_id', 'concepto_id', 'cuota_id', 'importe', 'estado', 'emitida_por',
        'recibo_folio', 'pagada_en', 'pago_registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoOrdenPago::class,
            'importe' => 'decimal:2',
            'pagada_en' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (OrdenPago $orden): void {
            if ($orden->isDirty(['importe', 'cuota_id', 'concepto_id', 'tramite_id', 'folio'])) {
                throw new LogicException('El importe y la cuota de una orden emitida no cambian.');
            }
        });
        static::deleting(fn () => throw new LogicException('Las órdenes de pago no se borran; se cancelan.'));
    }

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class);
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoCobro::class, 'concepto_id');
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(Cuota::class);
    }
}
