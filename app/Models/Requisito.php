<?php

namespace App\Models;

use App\Enums\MultiplicadorRequisito;
use App\Enums\ParteTramite;
use App\Enums\TipoTramite;
use App\Models\Concerns\Versionable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Requisito documental de un trámite, versionado. Un trámite guardará la versión exacta
 * que se le exigió, aunque el catálogo cambie después.
 */
class Requisito extends Model
{
    use Versionable;

    protected $table = 'requisitos';

    protected array $columnasGeneradas = ['version_abierta'];

    /** Columnas que identifican al mismo registro en todas sus versiones. */
    protected array $llaveVersion = ['tipo_tramite', 'clave'];

    /** Corregir la redacción o una errata no cambia ninguna decisión: se permite en vigor. */
    protected array $editablesEnVigor = ['documento', 'descripcion', 'presentacion', 'fundamento', 'orden'];

    protected $fillable = [
        'tipo_tramite', 'clave', 'documento', 'descripcion', 'de_quien', 'cantidad', 'se_multiplica_por',
        'presentacion', 'obligatorio', 'vigencia_maxima_dias', 'grupo_alternativa', 'emitido_por_sistema',
        'activa_pago_derechos', 'exento_con_orden_autoridad', 'fundamento', 'orden', 'vigente_desde', 'vigente_hasta',
    ];

    protected function casts(): array
    {
        return [
            'tipo_tramite' => TipoTramite::class,
            'de_quien' => ParteTramite::class,
            'cantidad' => 'integer',
            'se_multiplica_por' => MultiplicadorRequisito::class,
            'obligatorio' => 'boolean',
            'vigencia_maxima_dias' => 'integer',
            'emitido_por_sistema' => 'boolean',
            'activa_pago_derechos' => 'boolean',
            'exento_con_orden_autoridad' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function scopeDeTramite(Builder $query, TipoTramite $tipo, ?CarbonInterface $fecha = null): Builder
    {
        return $query->where('tipo_tramite', $tipo->value)->vigenteEn($fecha)->orderBy('orden');
    }

    /** ¿Un documento expedido en $expedicion sigue vigente para este requisito en $fecha? */
    public function documentoVigente(CarbonInterface $expedicion, ?CarbonInterface $fecha = null): bool
    {
        if ($this->vigencia_maxima_dias === null) {
            return true;
        }

        return $expedicion->copy()->addDays($this->vigencia_maxima_dias)->gte(($fecha ?? Carbon::today())->copy()->startOfDay());
    }

    /** Ya usada: no se puede deshacer, sólo reemplazar con una versión nueva. */
    public function estaEnUso(): bool
    {
        return TramiteRequisito::where('requisito_id', $this->id)->exists();
    }
}
