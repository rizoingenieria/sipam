<?php

namespace App\Services\Reportes;

use App\Enums\EstadoTramite;
use App\Enums\TipoMovimiento;
use App\Enums\TipoTramite;
use App\Models\Movimiento;
use App\Models\Panteon;
use App\Models\Tramite;
use Illuminate\Support\Facades\DB;

/**
 * Informes periódicos del Encargado de Panteones:
 * - mensual: movimientos, trámites, ingresos y lotes disponibles (art. 21, frs. IV, XXIII y XXIV);
 * - anual a la Secretaría de Salud: ubicación, denominación, extensión y capacidad de cada panteón (art. 21, fr. XXX).
 */
final class Informes
{
    public function mensual(int $anio, int $mes): array
    {
        $p = Periodo::mes($anio, $mes);

        return [
            'periodo' => $p,
            'movimientos' => $this->movimientosPorPanteon($p),
            'tramites' => $this->tramites($p),
            'ingresos' => Ingresos::porConcepto($p),
            'pendientesDePago' => Ingresos::pendientes($p),
            'disponibilidad' => Disponibilidad::porSeccion(),
        ];
    }

    public function anualSalud(int $anio): array
    {
        $p = Periodo::anio($anio);
        $disponibles = Disponibilidad::porPanteon()->keyBy('panteon_id');
        $movs = $this->movimientosPorPanteon($p)->keyBy('panteon_id');

        return [
            'periodo' => $p,
            'anio' => $anio,
            'panteones' => Panteon::orderBy('nombre')->get()->map(fn (Panteon $pt) => (object) [
                'nombre' => $pt->nombre,
                'domicilio' => $pt->domicilio,
                'extension' => $pt->extension_m2,
                'capacidad' => $pt->capacidad,
                'estado' => $pt->estado,
                'registrados' => $disponibles[$pt->id]->total ?? 0,
                'ocupados' => $disponibles[$pt->id]->ocupados ?? 0,
                'libres' => $disponibles[$pt->id]->libres ?? 0,
                'inhumaciones' => $movs[$pt->id]->inhumacion ?? 0,
                'exhumaciones' => $movs[$pt->id]->exhumacion ?? 0,
            ]),
        ];
    }

    /** Movimientos por panteón y tipo; cuenta el panteón de destino para inhumaciones y de origen para el resto. */
    private function movimientosPorPanteon(Periodo $p)
    {
        $filas = Movimiento::query()
            ->leftJoin('espacios as e', 'e.id', '=', DB::raw("CASE WHEN movimientos.tipo IN ('inhumacion', 'reinhumacion') THEN movimientos.espacio_destino_id ELSE movimientos.espacio_origen_id END"))
            ->leftJoin('lotes as l', 'l.id', '=', 'e.lote_id')
            ->leftJoin('secciones as s', 's.id', '=', 'l.seccion_id')
            ->leftJoin('panteones as pt', 'pt.id', '=', 's.panteon_id')
            ->whereBetween('movimientos.fecha_hora', $p->entre())
            ->groupBy('pt.id', 'pt.nombre', 'movimientos.tipo')
            ->selectRaw('pt.id as panteon_id, pt.nombre as panteon, movimientos.tipo as tipo, COUNT(*) as n')
            ->toBase()->get();

        return $filas->groupBy('panteon_id')->map(function ($grupo) {
            $fila = (object) ['panteon_id' => $grupo->first()->panteon_id, 'panteon' => $grupo->first()->panteon ?? 'Sin panteón'];
            foreach (TipoMovimiento::cases() as $t) {
                $fila->{$t->value} = (int) ($grupo->firstWhere('tipo', $t->value)->n ?? 0);
            }

            return $fila;
        })->values();
    }

    /** @return list<array{tipo: string, capturados: int, concluidos: int, rechazados: int, caducados: int, en_curso: int}> */
    private function tramites(Periodo $p): array
    {
        $filas = [];
        foreach (TipoTramite::cases() as $tipo) {
            $base = fn () => Tramite::where('tipo', $tipo->value);
            $filas[] = [
                'tipo' => $tipo->getLabel(),
                'capturados' => $base()->whereBetween('created_at', $p->entre())->count(),
                'concluidos' => $base()->where('estado', EstadoTramite::Concluido->value)->whereBetween('resuelto_en', $p->entre())->count(),
                'rechazados' => $base()->where('estado', EstadoTramite::Rechazado->value)->whereBetween('resuelto_en', $p->entre())->count(),
                'caducados' => $base()->where('estado', EstadoTramite::Caducado->value)->whereBetween('updated_at', $p->entre())->count(),
                'en_curso' => $base()->whereNotIn('estado', [EstadoTramite::Concluido->value, EstadoTramite::Rechazado->value, EstadoTramite::Caducado->value])
                    ->where('created_at', '<=', $p->hasta)->count(),
            ];
        }

        return $filas;
    }
}
