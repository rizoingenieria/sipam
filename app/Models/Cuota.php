<?php

namespace App\Models;

use App\Models\Concerns\Versionable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Importe vigente de un concepto (y sección, si el concepto depende de ella).
 * Versionada: el cambio de tarifa anual se hace con nuevaVersion() y el acuerdo de Cabildo nuevo.
 */
class Cuota extends Model
{
    use Versionable;

    protected $table = 'cuotas';

    protected array $columnasGeneradas = ['seccion_clave', 'version_abierta'];

    /** Columnas que identifican al mismo registro en todas sus versiones. */
    protected array $llaveVersion = ['concepto_id', 'seccion_id'];

    /** Corregir la redacción o una errata no cambia ninguna decisión: se permite en vigor. */
    protected array $editablesEnVigor = ['acuerdo_cabildo', 'fundamento'];

    protected $fillable = [
        'concepto_id', 'seccion_id', 'importe', 'acuerdo_cabildo', 'fundamento', 'vigente_desde', 'vigente_hasta',
    ];

    protected function casts(): array
    {
        return ['importe' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        // Un concepto por sección exige sección; uno general no la admite.
        static::saving(function (Cuota $cuota): void {
            $concepto = ConceptoCobro::findOrFail($cuota->concepto_id);

            if ($concepto->por_seccion && $cuota->seccion_id === null) {
                throw new InvalidArgumentException("El concepto «{$concepto->nombre}» cambia por sección: indique la sección.");
            }
            if (! $concepto->por_seccion && $cuota->seccion_id !== null) {
                throw new InvalidArgumentException("El concepto «{$concepto->nombre}» tiene una sola cuota general.");
            }
        });
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoCobro::class, 'concepto_id');
    }

    public function seccion(): BelongsTo
    {
        return $this->belongsTo(Seccion::class);
    }

    public static function vigentePara(ConceptoCobro $concepto, ?Seccion $seccion = null, ?CarbonInterface $fecha = null): ?self
    {
        return static::query()
            ->where('concepto_id', $concepto->id)
            ->when(
                $concepto->por_seccion,
                fn ($q) => $q->where('seccion_id', $seccion?->id ?? 0),
                fn ($q) => $q->whereNull('seccion_id'),
            )
            ->vigenteEn($fecha)
            ->first();
    }

    /** Ya usada: no se puede deshacer, sólo reemplazar con una versión nueva. */
    public function estaEnUso(): bool
    {
        return OrdenPago::where('cuota_id', $this->id)->exists();
    }
}
