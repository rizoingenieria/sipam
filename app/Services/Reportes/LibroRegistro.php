<?php

namespace App\Services\Reportes;

use App\Enums\TipoMovimiento;
use App\Models\Movimiento;
use Illuminate\Database\Eloquent\Builder;

/**
 * Libro cronológico de inhumaciones, exhumaciones, traslados y reinhumaciones (art. 21, fr. III).
 * Se arma de los movimientos, que no se editan ni se borran: el libro no puede alterarse.
 */
final class LibroRegistro
{
    public const LIMITE_PDF = 1500;

    public static function consulta(Periodo $p, ?TipoMovimiento $tipo = null, ?int $panteonId = null): Builder
    {
        return Movimiento::query()
            ->with(['inhumado', 'tramite', 'solicitante', 'espacioOrigen.lote.seccion.panteon', 'espacioDestino.lote.seccion.panteon'])
            ->whereBetween('fecha_hora', $p->entre())
            ->when($tipo, fn (Builder $q) => $q->where('tipo', $tipo->value))
            ->when($panteonId, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('espacioOrigen.lote.seccion', fn (Builder $s) => $s->where('panteon_id', $panteonId))
                ->orWhereHas('espacioDestino.lote.seccion', fn (Builder $s) => $s->where('panteon_id', $panteonId))))
            ->orderBy('fecha_hora')->orderBy('id');
    }

    /**
     * Un renglón del libro con los datos que pide el art. 21, fr. III, para el tipo de movimiento.
     *
     * @return array<string, ?string>
     */
    public static function renglon(Movimiento $m, bool $verCausa): array
    {
        $i = $m->inhumado;
        $ubicacion = fn ($e) => $e?->clave_completa;

        return [
            'fecha' => $m->fecha_hora->format('d/m/Y H:i'),
            'movimiento' => $m->tipo->getLabel(),
            'nombre' => $i?->nombre_completo,
            'sexo' => $i?->sexo?->getLabel(),
            'edad' => $i?->edad_al_fallecer !== null ? $i->edad_al_fallecer.' años' : null,
            'acta' => $i?->numero_acta_defuncion,
            'causa_fallecimiento' => $verCausa ? $i?->causa_fallecimiento : ($i?->causa_fallecimiento ? 'Reservada' : null),
            'lugar_fallecimiento' => $i?->lugar_fallecimiento,
            'origen' => $ubicacion($m->espacioOrigen),
            'destino' => $ubicacion($m->espacioDestino) ?? $m->destino_externo,
            'causa_movimiento' => $m->causa,
            'realizado_por' => $m->realizado_por,
            'solicitante' => $m->solicitante
                ? $m->solicitante->nombre_completo.($m->tipo === TipoMovimiento::Reinhumacion
                    ? collect([$m->solicitante->domicilio, $m->solicitante->telefono ? 'tel. '.$m->solicitante->telefono : null])->filter()->map(fn ($v) => ", {$v}")->implode('')
                    : '')
                : null,
            'permiso' => $m->permiso_folio,
            'tramite' => $m->tramite?->folio.($m->por_orden_autoridad ? ' (orden de autoridad)' : ''),
        ];
    }

    public const COLUMNAS = [
        'fecha' => 'Fecha y hora', 'movimiento' => 'Movimiento', 'nombre' => 'Nombre', 'sexo' => 'Sexo', 'edad' => 'Edad',
        'acta' => 'Acta de defunción', 'causa_fallecimiento' => 'Causa de fallecimiento', 'lugar_fallecimiento' => 'Lugar de fallecimiento',
        'origen' => 'Ubicación de origen', 'destino' => 'Ubicación o destino', 'causa_movimiento' => 'Causa del movimiento',
        'realizado_por' => 'Quién lo realiza', 'solicitante' => 'Solicitante', 'permiso' => 'Permiso', 'tramite' => 'Trámite',
    ];
}
