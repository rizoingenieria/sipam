<?php

namespace App\Filament\Support;

use App\Support\AccionBloqueada;
use App\Support\Motivo;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Avisos al usuario con la forma de la guía (sección 10): qué pasa, qué hacer y, en letra secundaria,
 * el fundamento. Los motivos de bloqueo se muestran todos juntos para resolverlos de una vez.
 */
final class Avisos
{
    /**
     * Ejecuta un paso; si algo lo bloquea, lo explica y deja abierta la ventana para corregir.
     *
     * @template T
     *
     * @param  callable(): T  $paso
     * @return T|null
     */
    public static function intentar(callable $paso, ?Action $accion = null): mixed
    {
        try {
            return $paso();
        } catch (AccionBloqueada $e) {
            self::bloqueado($e);
            $accion?->halt();

            return null;
        }
    }

    public static function bloqueado(AccionBloqueada $e): void
    {
        $n = count($e->motivos);

        Notification::make()
            ->danger()
            ->title($n === 1 ? 'Todavía no se puede' : "Faltan {$n} cosas para continuar")
            ->body(self::lista($e->motivos))
            ->persistent()
            ->send();
    }

    public static function listo(string $titulo, ?string $detalle = null): void
    {
        Notification::make()->success()->title($titulo)->body($detalle)->send();
    }

    /** @param list<Motivo> $motivos */
    public static function lista(array $motivos): HtmlString
    {
        $items = array_map(function (Motivo $m): string {
            $html = '<li style="margin-top:.5rem"><strong>'.e($m->problema).'</strong>';
            if ($m->queHacer) {
                $html .= '<br>'.e($m->queHacer);
            }
            if ($m->fundamento) {
                $html .= '<br><span style="font-size:.8rem;opacity:.75">'.e($m->fundamento).'</span>';
            }

            return $html.'</li>';
        }, $motivos);

        return new HtmlString('<ul style="margin:0;padding-left:1rem;list-style:disc">'.implode('', $items).'</ul>');
    }
}
