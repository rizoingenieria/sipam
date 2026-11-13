<?php

namespace App\Services\Tramites;

use App\Enums\EstadoOrdenPago;
use App\Enums\EstadoTramite;
use App\Enums\Permiso;
use App\Enums\TipoTramite;
use App\Models\OrdenPago;
use App\Models\Rol;
use App\Models\Tramite;
use App\Support\Formato;
use App\Support\Motivo;

/**
 * Traduce el estado de un trámite a «lo que sigue»: el paso, lo que falta y quién lo da.
 * La interfaz lo muestra arriba del expediente para que nadie tenga que saberse el proceso.
 * Los pendientes salen del mismo ValidadorTramite que aplica el gestor: lo que se ve es lo que se exige.
 */
final class GuiaTramite
{
    public function __construct(private readonly ValidadorTramite $validador) {}

    /** Versión corta para listados: no consulta nada. */
    public static function siguiente(EstadoTramite $estado, TipoTramite $tipo): string
    {
        return match ($estado) {
            EstadoTramite::Capturado, EstadoTramite::EnRevision => $tipo === TipoTramite::Adquisicion
                ? 'Completar el expediente y publicar'
                : 'Completar el expediente',
            EstadoTramite::EnPublicacion => 'Terminar la publicación',
            EstadoTramite::PorPagar => 'Registrar el recibo de pago',
            EstadoTramite::PorAutorizar => 'Autorizar',
            EstadoTramite::Concluido => 'Nada: está concluido',
            EstadoTramite::Rechazado => 'Nada: fue rechazado',
            EstadoTramite::Caducado => 'Nada: caducó',
        };
    }

    public function paso(Tramite $t): PasoTramite
    {
        return match ($t->estado) {
            EstadoTramite::Capturado, EstadoTramite::EnRevision => $t->tipo === TipoTramite::Adquisicion
                ? new PasoTramite(
                    'Completar el expediente y publicar la notificación',
                    'Recibe los documentos, registra a las personas, haz el cotejo y sube la responsiva firmada y las fotos de la notificación en estrados y en el lote. Con eso se registra la publicación (art. 34).',
                    $this->validador->incumplimientosPublicacion($t),
                    'registrarPublicacion', 'Registrar la publicación', Permiso::TramitesPublicar,
                )
                : new PasoTramite(
                    'Completar el expediente',
                    $t->por_orden_autoridad
                        ? 'Recibe los documentos y haz el cotejo. Por ser orden de autoridad no se cobra: al cerrar la revisión pasa directo a autorización.'
                        : 'Recibe los documentos, registra a las personas y haz el cotejo. Al cerrar la revisión se emite la orden de pago.',
                    $this->validador->incumplimientosRevision($t),
                    'cerrarRevision', $t->por_orden_autoridad ? 'Cerrar la revisión' : 'Cerrar la revisión y emitir la orden de pago', Permiso::TramitesCapturar,
                ),
            EstadoTramite::EnPublicacion => new PasoTramite(
                'Publicación en estrados',
                'La notificación está publicada desde el '.Formato::fecha($t->publicado_en).'. Cuando termine el plazo de publicación sin que nadie se oponga, emite la orden de pago.',
                $this->validador->candados($t),
                'concluirPublicacion', 'Terminar la publicación y emitir la orden de pago', Permiso::TramitesCapturar,
            ),
            EstadoTramite::PorPagar => new PasoTramite(
                'Esperando el pago',
                'El solicitante paga en Tesorería con la orden de pago y regresa con el recibo oficial. Captura el folio de ese recibo.',
                $t->ordenesPago()->with('concepto')->where('estado', EstadoOrdenPago::Emitida->value)->get()
                    ->map(fn (OrdenPago $o) => Motivo::de("Falta pagar la orden {$o->folio}: {$o->concepto->nombre}, ".Formato::dinero($o->importe).'.'))
                    ->all(),
                'registrarPago', 'Registrar recibo de pago', Permiso::TramitesRegistrarPagos,
            ),
            EstadoTramite::PorAutorizar => new PasoTramite(
                'Autorizar',
                'Revisa el expediente y, si todo está en orden, autorízalo. Quien capturó el trámite no puede autorizarlo.',
                $this->validador->incumplimientosConclusion($t),
                'concluir', 'Autorizar y concluir', Permiso::autorizar($t->tipo),
            ),
            EstadoTramite::Concluido => new PasoTramite('Trámite concluido', $this->resultado($t), terminado: true),
            EstadoTramite::Rechazado => new PasoTramite('Trámite rechazado', 'Motivo: '.($t->resolucion ?? 'sin motivo registrado.'), terminado: true),
            EstadoTramite::Caducado => new PasoTramite('Trámite caducado', ($t->resolucion ?? 'No se concluyó a tiempo.').' Si la persona sigue interesada, captura uno nuevo.', terminado: true),
        };
    }

    /** Nombres de los roles activos que pueden dar el paso, para saber a quién pasárselo. */
    public static function quienPuede(Permiso $permiso): string
    {
        $roles = Rol::where('activo', true)->whereJsonContains('permisos', $permiso->value)->orderBy('nombre')->pluck('nombre');

        return match ($roles->count()) {
            0 => 'Ningún rol tiene este permiso. Pide al administrador que lo asigne.',
            1 => $roles->first(),
            default => $roles->slice(0, -1)->implode(', ').' o '.$roles->last(),
        };
    }

    private function resultado(Tramite $t): string
    {
        $cuando = $t->resuelto_en ? ' el '.Formato::fecha($t->resuelto_en) : '';

        return match ($t->tipo) {
            TipoTramite::Adquisicion, TipoTramite::Regularizacion => "Se autorizó{$cuando} y se emitió el título ".($t->tituloResultante?->folio ?? '').'.',
            TipoTramite::Inhumacion => "Se autorizó{$cuando}. La inhumación quedó registrada en el libro.",
            TipoTramite::Exhumacion => "Se autorizó{$cuando}. La exhumación y el destino de los restos quedaron registrados.",
            TipoTramite::Construccion => "Se autorizó{$cuando} el permiso de obra.",
        };
    }
}
