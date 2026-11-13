<?php

namespace App\Services\Reportes;

use App\Enums\EstadoOrdenPago;
use App\Models\OrdenPago;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Órdenes pagadas en un periodo, por la fecha del recibo de Tesorería (art. 20, fr. XII, inciso d).
 * Sirve para conciliar con Tesorería: cada renglón trae el folio de la orden y el del recibo.
 */
final class Ingresos
{
    public static function consulta(Periodo $p, ?int $conceptoId = null): Builder
    {
        return OrdenPago::query()
            ->with(['concepto', 'tramite.solicitante'])
            ->where('estado', EstadoOrdenPago::Pagada->value)
            ->whereBetween('pagada_en', [$p->desde->toDateString(), $p->hasta->toDateString()])
            ->when($conceptoId, fn (Builder $q) => $q->where('concepto_id', $conceptoId))
            ->orderBy('pagada_en')->orderBy('id');
    }

    /** @return Collection<int, object{concepto: string, ordenes: int, importe: float}> */
    public static function porConcepto(Periodo $p): Collection
    {
        return self::consulta($p)->get()->groupBy('concepto_id')->map(fn (Collection $o) => (object) [
            'concepto' => $o->first()->concepto->nombre,
            'ordenes' => $o->count(),
            'importe' => (float) $o->sum('importe'),
        ])->sortBy('concepto')->values();
    }

    /** Órdenes emitidas que siguen sin pagarse al final del periodo. */
    public static function pendientes(Periodo $p): int
    {
        return OrdenPago::where('estado', EstadoOrdenPago::Emitida->value)->where('created_at', '<=', $p->hasta)->count();
    }
}
