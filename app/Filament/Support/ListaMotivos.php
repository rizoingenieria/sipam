<?php

namespace App\Filament\Support;

use App\Support\Motivo;
use Illuminate\Support\HtmlString;

/** Motivos como lista legible: el problema en negritas, qué hacer debajo, el fundamento chico. */
final class ListaMotivos
{
    /** @param  list<Motivo>  $motivos */
    public static function html(array $motivos): HtmlString
    {
        $html = '<ul style="margin:0;padding:0;list-style:none;display:grid;gap:.75rem">';
        foreach ($motivos as $m) {
            $html .= '<li style="padding-left:1rem;border-left:3px solid var(--sp-rule)">'
                .'<strong style="display:block;color:var(--sp-ink)">'.e($m->problema).'</strong>'
                .($m->queHacer ? '<span style="display:block;color:var(--sp-ink)">'.e($m->queHacer).'</span>' : '')
                .($m->fundamento ? '<small style="color:var(--sp-ink-soft)">'.e($m->fundamento).'</small>' : '')
                .'</li>';
        }

        return new HtmlString($html.'</ul>');
    }
}
