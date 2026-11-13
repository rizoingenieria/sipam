<?php

namespace App\Services\Reportes;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cuántos espacios hay por sección y en qué situación (art. 21, fr. XXIV: lotes disponibles).
 *
 * - Ocupados: tienen a alguien sepultado.
 * - Con título y vacíos: tienen dueño, pero nadie sepultado; sólo los usa su titular.
 * - Libres: sin título y sin sepultados; disponibles para adquirir o inhumar.
 * - No disponibles: marcados así en el inventario.
 */
final class Disponibilidad
{
    /** @return Collection<int, object{panteon_id: int, panteon: string, seccion: string, total: int, ocupados: int, con_titulo_vacios: int, libres: int, no_disponibles: int}> */
    public static function porSeccion(?int $panteonId = null): Collection
    {
        $ocupado = "EXISTS (SELECT 1 FROM inhumados i WHERE i.espacio_id = e.id AND i.estado = 'inhumado')";
        $conTitulo = "EXISTS (SELECT 1 FROM titulos t WHERE t.espacio_id = e.id AND t.estado = 'vigente')";

        return DB::table('espacios as e')
            ->join('lotes as l', 'l.id', '=', 'e.lote_id')
            ->join('secciones as s', 's.id', '=', 'l.seccion_id')
            ->join('panteones as p', 'p.id', '=', 's.panteon_id')
            ->when($panteonId, fn ($q) => $q->where('p.id', $panteonId))
            ->groupBy('p.id', 'p.nombre', 's.id', 's.clave')
            ->orderBy('p.nombre')->orderBy('s.clave')
            ->selectRaw("p.id as panteon_id, p.nombre as panteon, s.clave as seccion, COUNT(*) as total,
                SUM(CASE WHEN e.habilitado = 0 THEN 1 ELSE 0 END) as no_disponibles,
                SUM(CASE WHEN e.habilitado = 1 AND {$ocupado} THEN 1 ELSE 0 END) as ocupados,
                SUM(CASE WHEN e.habilitado = 1 AND NOT {$ocupado} AND {$conTitulo} THEN 1 ELSE 0 END) as con_titulo_vacios,
                SUM(CASE WHEN e.habilitado = 1 AND NOT {$ocupado} AND NOT {$conTitulo} THEN 1 ELSE 0 END) as libres")
            ->get()
            ->map(function ($r) {
                foreach (['total', 'ocupados', 'con_titulo_vacios', 'libres', 'no_disponibles'] as $c) {
                    $r->{$c} = (int) $r->{$c};
                }

                return $r;
            });
    }

    /** Totales por panteón. @return Collection<int, object> */
    public static function porPanteon(?int $panteonId = null): Collection
    {
        return self::porSeccion($panteonId)->groupBy('panteon_id')->map(fn (Collection $s) => (object) [
            'panteon_id' => $s->first()->panteon_id,
            'panteon' => $s->first()->panteon,
            'total' => $s->sum('total'),
            'ocupados' => $s->sum('ocupados'),
            'con_titulo_vacios' => $s->sum('con_titulo_vacios'),
            'libres' => $s->sum('libres'),
            'no_disponibles' => $s->sum('no_disponibles'),
        ])->values();
    }
}
