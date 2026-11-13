<?php

namespace App\Services\Documentos;

use App\Enums\EstadoOrdenPago;
use App\Enums\EstadoRequisitoEntregado;
use App\Enums\EstadoTramite;
use App\Enums\ParteTramite;
use App\Enums\Permiso;
use App\Enums\TipoTramite;
use App\Models\Titulo;
use App\Models\Tramite;

/**
 * Qué documentos se pueden imprimir de un trámite y cuáles hacen falta en el paso actual.
 * Así el botón correcto aparece donde se necesita: en «Qué sigue» y en el menú «Imprimir».
 *
 * Cada documento: ['etiqueta' => ..., 'url' => ...].
 */
final class DocumentosDelTramite
{
    public static function puedeConsultar(): bool
    {
        return auth()->user()?->puede(Permiso::TramitesVer) ?? false;
    }

    public static function puedeImprimirTitulos(): bool
    {
        $u = auth()->user();

        return $u !== null && ($u->puede(Permiso::AutorizarAdquisicion) || $u->puede(Permiso::AutorizarRegularizacion)
            || $u->puede(Permiso::TramitesEmitirDocumentos));
    }

    public static function urlTitulo(Titulo $titulo): string
    {
        return route('filament.panteones.documentos.titulo', $titulo);
    }

    /** @return list<array{etiqueta: string, url: string}> */
    public static function todos(Tramite $t): array
    {
        $docs = [];
        foreach ($t->ordenesPago()->with('concepto')->get() as $o) {
            $docs[] = ['etiqueta' => "Orden de pago {$o->folio} ({$o->concepto->nombre})", 'url' => route('filament.panteones.documentos.orden', $o)];
        }
        foreach ($t->requisitos()->whereHas('requisito', fn ($q) => $q->where('emitido_por_sistema', true))->with('requisito')->get() as $f) {
            $docs[] = ['etiqueta' => $f->requisito->documento.' (para firmar)', 'url' => route('filament.panteones.documentos.para-firmar', $f)];
        }
        if (in_array($t->tipo, [TipoTramite::Inhumacion, TipoTramite::Exhumacion], true)) {
            foreach ($t->participantesCon(ParteTramite::Autorizante) as $p) {
                $docs[] = ['etiqueta' => "Consentimiento de {$p->persona->nombre_completo}", 'url' => route('filament.panteones.documentos.consentimiento', $p)];
            }
        }
        if ($t->tipo === TipoTramite::Adquisicion) {
            $docs[] = ['etiqueta' => 'Aviso para estrados y para el lote', 'url' => route('filament.panteones.documentos.notificacion', $t)];
        }
        if ($r = self::resultado($t)) {
            $docs[] = $r;
        }

        return $docs;
    }

    /** Sólo lo que se necesita para dar el paso que sigue. @return list<array{etiqueta: string, url: string}> */
    public static function delPaso(Tramite $t): array
    {
        $docs = [];
        switch ($t->estado) {
            case EstadoTramite::Capturado:
            case EstadoTramite::EnRevision:
                foreach ($t->requisitos()->where('estado', EstadoRequisitoEntregado::Pendiente->value)
                    ->whereHas('requisito', fn ($q) => $q->where('emitido_por_sistema', true))->with('requisito')->get() as $f) {
                    $docs[] = ['etiqueta' => 'Imprimir '.mb_strtolower($f->requisito->documento), 'url' => route('filament.panteones.documentos.para-firmar', $f)];
                }
                if (in_array($t->tipo, [TipoTramite::Inhumacion, TipoTramite::Exhumacion], true)) {
                    foreach ($t->participantesCon(ParteTramite::Autorizante)->reject->comparecio() as $p) {
                        $docs[] = ['etiqueta' => 'Imprimir consentimiento de '.$p->persona->nombre, 'url' => route('filament.panteones.documentos.consentimiento', $p)];
                    }
                }
                if ($t->tipo === TipoTramite::Adquisicion) {
                    $docs[] = ['etiqueta' => 'Imprimir aviso para estrados', 'url' => route('filament.panteones.documentos.notificacion', $t)];
                }
                break;
            case EstadoTramite::PorPagar:
                foreach ($t->ordenesPago()->where('estado', EstadoOrdenPago::Emitida->value)->get() as $o) {
                    $docs[] = ['etiqueta' => "Imprimir orden de pago {$o->folio}", 'url' => route('filament.panteones.documentos.orden', $o)];
                }
                break;
            default:
                if ($r = self::resultado($t)) {
                    $docs[] = ['etiqueta' => 'Imprimir '.mb_strtolower($r['etiqueta']), 'url' => $r['url']];
                }
        }

        return $docs;
    }

    /** @return ?array{etiqueta: string, url: string} */
    private static function resultado(Tramite $t): ?array
    {
        if ($t->estado === EstadoTramite::Concluido && $t->titulo_resultante_id) {
            return self::puedeImprimirTitulos() ? ['etiqueta' => 'Título '.$t->tituloResultante->folio, 'url' => self::urlTitulo($t->tituloResultante)] : null;
        }
        if ($t->estado === EstadoTramite::Rechazado || ($t->estado === EstadoTramite::Concluido
            && in_array($t->tipo, [TipoTramite::Inhumacion, TipoTramite::Exhumacion, TipoTramite::Construccion], true))) {
            return ['etiqueta' => $t->estado === EstadoTramite::Rechazado ? 'Resolución de improcedencia' : 'Autorización', 'url' => route('filament.panteones.documentos.resolucion', $t)];
        }

        return null;
    }
}
