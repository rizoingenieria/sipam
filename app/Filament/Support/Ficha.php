<?php

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;

/** Lista de «dato: valor» con el mismo aspecto en todas las fichas. Omite los vacíos. */
final class Ficha
{
    /** @param  array<string, ?string>  $filas */
    public static function html(array $filas): HtmlString
    {
        $html = '<dl style="display:grid;grid-template-columns:max-content 1fr;gap:.5rem 1.5rem;margin:0">';
        foreach (array_filter($filas, fn ($v) => filled($v)) as $k => $v) {
            $html .= '<dt style="font-weight:600">'.e($k).'</dt><dd style="margin:0">'.e($v).'</dd>';
        }

        return new HtmlString($html.'</dl>');
    }
}
