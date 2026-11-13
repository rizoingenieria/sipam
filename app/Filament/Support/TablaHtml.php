<?php

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;

/** Tabla sencilla de resumen para las pantallas de informes. Estilos en línea con los tokens «Papel y tinta». */
final class TablaHtml
{
    /**
     * @param  list<string>  $encabezados
     * @param  iterable<list<mixed>>  $filas
     * @param  list<int>  $numericas  Índices de columnas que se alinean a la derecha.
     */
    public static function html(array $encabezados, iterable $filas, array $numericas = [], ?string $vacio = null): HtmlString
    {
        $alinear = fn (int $i) => in_array($i, $numericas, true) ? 'text-align:right;' : 'text-align:left;';
        $html = '<div style="display:block;width:100%;overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.95rem"><thead><tr style="border-bottom:1px solid var(--sp-ink)">';
        foreach ($encabezados as $i => $h) {
            $html .= '<th style="padding:.45rem .5rem;font-weight:600;'.$alinear($i).'">'.e($h).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        $hay = false;
        foreach ($filas as $fila) {
            $hay = true;
            $html .= '<tr style="border-bottom:1px solid var(--sp-rule)">';
            foreach (array_values($fila) as $i => $v) {
                $html .= '<td style="padding:.45rem .5rem;'.$alinear($i).'">'.e((string) $v).'</td>';
            }
            $html .= '</tr>';
        }
        if (! $hay) {
            $html .= '<tr><td colspan="'.count($encabezados).'" style="padding:.6rem .5rem;color:var(--sp-ink-soft)">'.e($vacio ?? 'Sin datos.').'</td></tr>';
        }

        return new HtmlString($html.'</tbody></table></div>');
    }
}
