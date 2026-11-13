<?php

namespace App\Services\Tramites;

use App\Enums\DestinoRestos;
use App\Enums\EstadoInhumado;
use App\Enums\EstadoOrdenPago;
use App\Enums\EstadoRequisitoEntregado;
use App\Enums\EstadoTitulo;
use App\Enums\EstadoTramite;
use App\Enums\MotivoExhumacion;
use App\Enums\MultiplicadorRequisito;
use App\Enums\OrigenTitulo;
use App\Enums\ParteTramite;
use App\Enums\Permiso;
use App\Enums\RolTitularidad;
use App\Enums\TipoArchivo;
use App\Enums\TipoMovimiento;
use App\Enums\TipoTramite;
use App\Models\Cesion;
use App\Models\ConceptoCobro;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Movimiento;
use App\Models\OrdenPago;
use App\Models\Parametro;
use App\Models\Parentesco;
use App\Models\Persona;
use App\Models\Regimen;
use App\Models\Requisito;
use App\Models\Titulo;
use App\Models\Tramite;
use App\Models\TramiteArchivo;
use App\Models\TramiteHistorial;
use App\Models\TramiteParticipante;
use App\Models\TramiteRequisito;
use App\Models\User;
use App\Support\Formato;
use App\Support\Motivo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Único punto por el que un trámite cambia. Cada paso:
 * 1. verifica que el usuario tenga el permiso de ese paso;
 * 2. bloquea la fila del trámite y confirma su estado (dos personas no lo mueven a la vez);
 * 3. valida sus candados y, si algo falta, dice todo lo que falta;
 * 4. aplica sus efectos en una transacción y deja huella en el historial.
 *
 * Al concluir:
 * - Adquisición: emite el título.
 * - Regularización: sustituye el título y registra la cesión (art. 27) o la herencia (art. 67).
 * - Inhumación: registra al finado y su asiento; si la fosa se reutiliza, deja los restos anteriores al pie (art. 44).
 * - Exhumación: asienta la exhumación y el destino de los restos.
 * - Construcción: autoriza el permiso.
 */
final class GestorTramites
{
    public function __construct(
        private readonly ValidadorTramite $validador,
        private readonly Folios $folios,
    ) {}

    // ── Captura ───────────────────────────────────────────────────────────────

    /**
     * @param  bool  $porOrdenAutoridad  Inhumación remitida por la fiscalía, el servicio médico forense o un juez
     *                                   (art. 46 y 68): sin autorizante ni pago. En exhumación lo decide el motivo.
     */
    public function capturar(
        TipoTramite $tipo,
        Espacio $espacio,
        Persona $solicitante,
        array $datos,
        User $usuario,
        ?Inhumado $inhumado = null,
        ?MotivoExhumacion $motivo = null,
        ?string $causaFallecimiento = null,
        bool $porOrdenAutoridad = false,
    ): Tramite {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'capturar trámites');
        if ($tipo === TipoTramite::Exhumacion && ($inhumado === null || $motivo === null)) {
            throw TramiteException::por(Motivo::de('Faltan la persona a exhumar y el motivo de la exhumación.', 'Elígelos antes de guardar.', 'Art. 41'));
        }

        $datos = ReglasDatos::validar($tipo, $datos, $causaFallecimiento);

        $tramite = new Tramite([
            'tipo' => $tipo,
            'estado' => EstadoTramite::Capturado,
            'espacio_id' => $espacio->id,
            'titulo_id' => $espacio->tituloVigente?->id,
            'inhumado_id' => $inhumado?->id,
            'solicitante_id' => $solicitante->id,
            'motivo_exhumacion' => $motivo,
            'por_orden_autoridad' => $tipo === TipoTramite::Exhumacion
                ? $motivo === MotivoExhumacion::OrdenAutoridad
                : ($tipo === TipoTramite::Inhumacion && $porOrdenAutoridad),
            'datos' => $datos,
            'causa_fallecimiento' => $tipo === TipoTramite::Inhumacion ? $causaFallecimiento : null,
            'capturado_por' => $usuario->id,
        ]);

        $motivos = $this->validador->candados($tramite);
        if ($motivos !== []) {
            throw new TramiteException($motivos);
        }

        return DB::transaction(function () use ($tramite, $tipo, $usuario): Tramite {
            $tramite->folio = $this->folios->siguiente(Parametro::texto("serie_folio_{$tipo->value}"));
            if ($tipo === TipoTramite::Adquisicion) {
                $tramite->caduca_en = Carbon::today()->addMonthsNoOverflow(Parametro::entero('caducidad_adquisicion_meses'));
            }
            $tramite->save();

            $this->copiarRequisitos($tramite);
            $this->historial($tramite, null, EstadoTramite::Capturado, $usuario, 'Captura del trámite.');

            return $tramite;
        });
    }

    /** Copia los requisitos vigentes: el trámite conserva la versión exacta que se le exigió. */
    private function copiarRequisitos(Tramite $t): void
    {
        $espacio = $t->espacio()->with('tituloVigente')->first();

        foreach (Requisito::deTramite($t->tipo)->get() as $requisito) {
            $veces = match ($requisito->se_multiplica_por) {
                MultiplicadorRequisito::Titulares => max(1, $espacio->tituloVigente?->titulares()->count() ?? 1),
                MultiplicadorRequisito::Inhumados => max(1, $espacio->inhumados()->count()),
                null => $requisito->cantidad,
            };
            $noAplica = $t->por_orden_autoridad && $requisito->exento_con_orden_autoridad;

            for ($i = 1; $i <= $veces; $i++) {
                TramiteRequisito::create([
                    'tramite_id' => $t->id,
                    'requisito_id' => $requisito->id,
                    'ordinal' => $i,
                    'estado' => $noAplica ? EstadoRequisitoEntregado::NoAplica : EstadoRequisitoEntregado::Pendiente,
                    'observaciones' => $noAplica ? 'Por orden de autoridad: no causa derechos (art. 68).' : null,
                ]);
            }
        }
    }

    // ── Integración del expediente ────────────────────────────────────────────

    public function entregarRequisito(TramiteRequisito $fila, User $usuario, ?Carbon $fechaExpedicion = null, ?string $folio = null, ?Persona $persona = null): TramiteRequisito
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'recibir documentos');
        $this->exigirEditable($fila->tramite);
        $requisito = $fila->requisito;

        if ($requisito->emitido_por_sistema) {
            throw TramiteException::por(Motivo::de("«{$requisito->documento}» lo firma el ayuntamiento; no lo trae el solicitante.", 'Usa la opción para subir el documento firmado.'));
        }
        if ($requisito->vigencia_maxima_dias !== null) {
            if ($fechaExpedicion === null) {
                throw TramiteException::por(Motivo::de("Falta la fecha de expedición de «{$requisito->documento}».",
                    'Escríbela tal como aparece en el documento: no puede tener más de '.Formato::cantidad($requisito->vigencia_maxima_dias, 'día').'.'));
            }
            if (! $requisito->documentoVigente($fechaExpedicion)) {
                $limite = $fechaExpedicion->copy()->addDays($requisito->vigencia_maxima_dias);
                throw TramiteException::por(Motivo::de(
                    "«{$requisito->documento}» ya no está vigente: se expidió el ".Formato::fecha($fechaExpedicion).' y sólo valía hasta el '.Formato::fecha($limite).'.',
                    'Pide al solicitante uno expedido hace menos de '.Formato::cantidad($requisito->vigencia_maxima_dias, 'día').'.',
                    $requisito->fundamento,
                ));
            }
        }
        if ($requisito->activa_pago_derechos && blank($folio)) {
            throw TramiteException::por(Motivo::de('Falta el folio del comprobante de Tesorería.', 'Cópialo del comprobante que trae el solicitante.'));
        }

        return DB::transaction(function () use ($fila, $usuario, $fechaExpedicion, $folio, $persona, $requisito): TramiteRequisito {
            $fila->update([
                'estado' => EstadoRequisitoEntregado::Entregado,
                'fecha_expedicion' => $fechaExpedicion,
                'folio_documento' => $folio,
                'persona_id' => $persona?->id,
                'revisado_por' => $usuario->id,
                'revisado_en' => Carbon::now(),
            ]);

            if ($requisito->activa_pago_derechos) {
                $fila->tramite->update([
                    'pago_derechos_al_corriente' => true,
                    'pago_derechos_folio' => $folio,
                    'pago_derechos_fecha' => $fechaExpedicion ?? Carbon::today(),
                ]);
            }

            return $fila;
        });
    }

    /**
     * Documentos que firma el ayuntamiento (responsiva, carta compromiso): se sube el escaneo firmado.
     * La firma autógrafa es la evidencia; cuando el sistema genere el PDF, se imprimirá, firmará y escaneará igual.
     */
    public function emitirDocumento(TramiteRequisito $fila, User $usuario, UploadedFile $escaneoFirmado): TramiteRequisito
    {
        $this->exigirPermiso($usuario, Permiso::TramitesEmitirDocumentos, 'firmar responsivas y cartas compromiso');
        $requisito = $fila->requisito;
        if (! $requisito->emitido_por_sistema) {
            throw TramiteException::por(Motivo::de("«{$requisito->documento}» lo trae el solicitante; no lo firma el ayuntamiento.", 'Márcalo como entregado.'));
        }
        $this->exigirAbierto($fila->tramite);

        return DB::transaction(function () use ($fila, $usuario, $escaneoFirmado, $requisito): TramiteRequisito {
            $this->guardarArchivo($fila->tramite, $escaneoFirmado, TipoArchivo::DocumentoEmitido, $usuario, $fila, $requisito->documento);
            $fila->update([
                'estado' => EstadoRequisitoEntregado::Entregado,
                'folio_documento' => "{$fila->tramite->folio}-{$requisito->clave}",
                'revisado_por' => $usuario->id,
                'revisado_en' => Carbon::now(),
            ]);

            return $fila;
        });
    }

    public function marcarNoAplica(TramiteRequisito $fila, User $usuario, string $motivo): TramiteRequisito
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'marcar requisitos que no aplican');
        $this->exigirEditable($fila->tramite);
        if ($fila->requisito->obligatorio && $fila->requisito->grupo_alternativa === null) {
            throw TramiteException::por(Motivo::de("«{$fila->requisito->documento}» es obligatorio; no puede marcarse como «no aplica».", 'Recíbelo para continuar.'));
        }
        if (blank($motivo)) {
            throw TramiteException::por(Motivo::de('Falta explicar por qué no aplica.', 'Escribe el motivo en una frase.'));
        }

        $fila->update([
            'estado' => EstadoRequisitoEntregado::NoAplica,
            'observaciones' => $motivo,
            'revisado_por' => $usuario->id,
            'revisado_en' => Carbon::now(),
        ]);

        return $fila;
    }

    /**
     * Corrige una recepción marcada por error: el documento vuelve a quedar pendiente.
     * Sólo mientras el expediente se integra; si era el comprobante de pago de derechos, se desmarca también.
     */
    public function devolverRequisito(TramiteRequisito $fila, User $usuario): TramiteRequisito
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'corregir documentos recibidos');
        $this->exigirEditable($fila->tramite);
        if ($fila->requisito->emitido_por_sistema) {
            throw TramiteException::por(Motivo::de("«{$fila->requisito->documento}» ya se firmó y su escaneo es la evidencia.", 'Si hay un error, rechaza el trámite explicando el motivo.'));
        }

        return DB::transaction(function () use ($fila, $usuario): TramiteRequisito {
            $fila->update([
                'estado' => EstadoRequisitoEntregado::Pendiente,
                'fecha_expedicion' => null, 'folio_documento' => null, 'persona_id' => null, 'observaciones' => null,
                'revisado_por' => $usuario->id, 'revisado_en' => Carbon::now(),
            ]);
            if ($fila->requisito->activa_pago_derechos) {
                $fila->tramite->update(['pago_derechos_al_corriente' => false, 'pago_derechos_folio' => null, 'pago_derechos_fecha' => null]);
            }

            return $fila;
        });
    }

    public function agregarParticipante(Tramite $t, Persona $persona, ParteTramite $papel, User $usuario, ?Parentesco $parentesco = null, ?int $aniosResidencia = null): TramiteParticipante
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'registrar personas en el trámite');
        $this->exigirEditable($t);

        return TramiteParticipante::create([
            'tramite_id' => $t->id,
            'persona_id' => $persona->id,
            'papel' => $papel,
            'parentesco_id' => $parentesco?->id,
            'anios_residencia' => $aniosResidencia,
        ]);
    }

    /** Quita a una persona registrada por error, mientras el expediente se integra. */
    public function quitarParticipante(TramiteParticipante $p, User $usuario): void
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'quitar personas del trámite');
        $this->exigirEditable($p->tramite);
        $p->delete();
    }

    /** Comparecencia en persona: no se acepta carta poder. */
    public function registrarComparecencia(TramiteParticipante $p, User $usuario): TramiteParticipante
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'registrar comparecencias');
        $this->exigirAbierto($p->tramite);
        $p->update(['comparecio_en' => Carbon::now(), 'comparecencia_registrada_por' => $usuario->id]);

        return $p;
    }

    /** Cotejo exhaustivo en presencia del titular o posesionario (art. 21, fr. XXIX). */
    public function registrarCotejo(Tramite $t, User $usuario, ?string $observaciones = null): Tramite
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'registrar el cotejo');
        $this->exigirEditable($t);
        $t->update(['cotejo_en' => Carbon::now(), 'cotejo_por' => $usuario->id, 'cotejo_observaciones' => $observaciones]);

        return $t;
    }

    // ── Evidencias ────────────────────────────────────────────────────────────

    public function adjuntarArchivo(Tramite $t, UploadedFile $archivo, TipoArchivo $tipo, User $usuario, ?TramiteRequisito $fila = null, ?string $descripcion = null): TramiteArchivo
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'subir fotos y documentos');
        $this->exigirAbierto($t);

        return $this->guardarArchivo($t, $archivo, $tipo, $usuario, $fila, $descripcion);
    }

    public function quitarArchivo(TramiteArchivo $archivo, User $usuario): void
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'quitar fotos y documentos');
        $this->exigirAbierto($archivo->tramite);
        $archivo->delete();
    }

    private function guardarArchivo(Tramite $t, UploadedFile $archivo, TipoArchivo $tipo, User $usuario, ?TramiteRequisito $fila, ?string $descripcion): TramiteArchivo
    {
        $maximoMb = Parametro::entero('archivo_tamano_maximo_mb');
        // Por su contenido, nunca por su nombre: un texto renombrado a «.jpg» no pasa.
        $origen = $archivo->getRealPath();
        $mime = $origen ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($origen) : (string) $archivo->getMimeType();

        if (! in_array($mime, $tipo->tiposPermitidos(), true)) {
            throw TramiteException::por(Motivo::de("El archivo «{$archivo->getClientOriginalName()}» no es de un tipo aceptado.",
                "Sube una foto o documento en {$tipo->tiposEnPalabras()}."));
        }
        if ($archivo->getSize() > $maximoMb * 1024 * 1024) {
            throw TramiteException::por(Motivo::de("El archivo «{$archivo->getClientOriginalName()}» pesa más de {$maximoMb} MB.",
                'Tómalo con menor resolución o pide al administrador que suba el límite.'));
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
        };
        $sha256 = $origen ? hash_file('sha256', $origen) : hash('sha256', (string) $archivo->get());
        $disco = 'local'; // privado: storage/app/private
        $ruta = $archivo->storeAs('tramites/'.$t->folio, Str::uuid()->toString().'.'.$extension, $disco);

        return TramiteArchivo::create([
            'tramite_id' => $t->id,
            'tramite_requisito_id' => $fila?->id,
            'tipo' => $tipo,
            'disco' => $disco,
            'ruta' => $ruta,
            'nombre_original' => Str::limit($archivo->getClientOriginalName(), 250, ''),
            'mime' => $mime,
            'bytes' => $archivo->getSize(),
            'sha256' => $sha256,
            'descripcion' => $descripcion,
            'subido_por' => $usuario->id,
        ]);
    }

    // ── Transiciones ──────────────────────────────────────────────────────────

    public function iniciarRevision(Tramite $t, User $usuario): Tramite
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'revisar trámites');

        return DB::transaction(function () use ($t, $usuario): Tramite {
            $this->bloquear($t, EstadoTramite::Capturado);

            return $this->cambiarEstado($t, EstadoTramite::EnRevision, $usuario);
        });
    }

    /** Cierra la revisión: emite las órdenes de pago o, si ordena la autoridad, pasa a autorización. */
    public function completarRevision(Tramite $t, User $usuario): Tramite
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'cerrar la revisión');

        return DB::transaction(function () use ($t, $usuario): Tramite {
            $this->bloquear($t, EstadoTramite::EnRevision);
            if ($t->tipo === TipoTramite::Adquisicion) {
                throw TramiteException::por(Motivo::de('La adquisición pasa primero por la publicación en estrados y en el lote.', 'Registra la publicación.', 'Art. 34'));
            }
            $this->exigirSinMotivos($this->validador->incumplimientosRevision($t));

            $t->save(); // conserva la evaluación de plazo hecha por el validador
            if ($t->por_orden_autoridad) {
                return $this->cambiarEstado($t, EstadoTramite::PorAutorizar, $usuario, 'Por orden de autoridad: sin pago de derechos (art. 68).');
            }
            $this->emitirOrdenes($t, $usuario);

            return $this->cambiarEstado($t, EstadoTramite::PorPagar, $usuario);
        });
    }

    /** Adquisición: con la responsiva firmada y las fotos, se registra la publicación (art. 34). */
    public function registrarPublicacion(Tramite $t, User $usuario, Carbon $fecha): Tramite
    {
        $this->exigirPermiso($usuario, Permiso::TramitesPublicar, 'registrar publicaciones en estrados');

        return DB::transaction(function () use ($t, $usuario, $fecha): Tramite {
            $this->bloquear($t, EstadoTramite::EnRevision);
            if ($t->tipo !== TipoTramite::Adquisicion) {
                throw TramiteException::por(Motivo::de('Sólo la adquisición se publica en estrados.', 'Cierra la revisión para emitir la orden de pago.'));
            }
            $this->exigirSinMotivos($this->validador->incumplimientosPublicacion($t));

            $t->update(['publicado_en' => $fecha]);

            return $this->cambiarEstado($t, EstadoTramite::EnPublicacion, $usuario, 'Publicado en estrados y en el lote el '.Formato::fecha($fecha).'.');
        });
    }

    public function concluirPublicacion(Tramite $t, User $usuario): Tramite
    {
        $this->exigirPermiso($usuario, Permiso::TramitesCapturar, 'concluir la publicación');

        return DB::transaction(function () use ($t, $usuario): Tramite {
            $this->bloquear($t, EstadoTramite::EnPublicacion);
            $this->exigirSinMotivos($this->validador->candados($t));
            $this->emitirOrdenes($t, $usuario);

            return $this->cambiarEstado($t, EstadoTramite::PorPagar, $usuario);
        });
    }

    /** Registra el recibo oficial de Tesorería. Con todo pagado, el trámite pasa a autorización. */
    public function registrarPago(OrdenPago $orden, string $reciboFolio, Carbon $fecha, User $usuario): OrdenPago
    {
        $this->exigirPermiso($usuario, Permiso::TramitesRegistrarPagos, 'registrar recibos de Tesorería');
        if (blank($reciboFolio)) {
            throw TramiteException::por(Motivo::de('Falta el folio del recibo de Tesorería.', 'Cópialo del recibo oficial que trae el solicitante.'));
        }

        return DB::transaction(function () use ($orden, $reciboFolio, $fecha, $usuario): OrdenPago {
            $t = $orden->tramite;
            $this->bloquear($t, EstadoTramite::PorPagar);
            $orden->refresh();
            if ($orden->estado !== EstadoOrdenPago::Emitida) {
                throw TramiteException::por(Motivo::de("La orden {$orden->folio} ya está {$this->minuscula($orden->estado->getLabel())}.", 'Revisa las órdenes del trámite.'));
            }

            $orden->update([
                'estado' => EstadoOrdenPago::Pagada,
                'recibo_folio' => $reciboFolio,
                'pagada_en' => $fecha,
                'pago_registrado_por' => $usuario->id,
            ]);

            if ($t->todoPagado()) {
                $this->cambiarEstado($t, EstadoTramite::PorAutorizar, $usuario, 'Pago completo registrado.');
            }

            return $orden;
        });
    }

    /** Autoriza y aplica los efectos. Quien capturó el trámite no lo autoriza. */
    public function concluir(Tramite $t, User $usuario, ?string $resolucion = null): Tramite
    {
        $this->exigirPermiso($usuario, Permiso::autorizar($t->tipo), 'autorizar este tipo de trámite');

        return DB::transaction(function () use ($t, $usuario, $resolucion): Tramite {
            // El bloqueo va antes de validar: así dos autorizaciones simultáneas no aplican dos veces sus efectos.
            $this->bloquear($t, EstadoTramite::PorAutorizar);
            if ((int) $t->capturado_por === (int) $usuario->id) {
                throw TramiteException::por(Motivo::de('Capturaste este trámite, así que no puedes autorizarlo tú.', 'Pide a otra persona con permiso que lo autorice.'));
            }
            $this->exigirSinMotivos($this->validador->incumplimientosConclusion($t));

            match ($t->tipo) {
                TipoTramite::Adquisicion => $this->emitirTitulo($t, OrigenTitulo::Adquisicion, null),
                TipoTramite::Regularizacion => $this->sustituirTitulo($t),
                TipoTramite::Inhumacion => $this->inhumar($t, $usuario),
                TipoTramite::Exhumacion => $this->exhumar($t, $usuario),
                TipoTramite::Construccion => null,
            };

            $t->fill([
                'resolucion' => $resolucion ?? 'Procedente.',
                'resuelto_en' => Carbon::now(),
                'resuelto_por' => $usuario->id,
            ])->save();

            return $this->cambiarEstado($t, EstadoTramite::Concluido, $usuario, $resolucion);
        });
    }

    public function rechazar(Tramite $t, User $usuario, string $motivo): Tramite
    {
        $this->exigirPermiso($usuario, Permiso::TramitesRechazar, 'rechazar trámites');
        if (blank($motivo)) {
            throw TramiteException::por(Motivo::de('Falta escribir por qué se rechaza.', 'Explica el motivo y, si puedes, el artículo que lo funda; el solicitante lo leerá.'));
        }

        return DB::transaction(function () use ($t, $usuario, $motivo): Tramite {
            $this->bloquear($t, ...array_filter(EstadoTramite::cases(), fn (EstadoTramite $e) => ! $e->esTerminal()));
            $t->fill(['resolucion' => $motivo, 'resuelto_en' => Carbon::now(), 'resuelto_por' => $usuario->id])->save();
            $this->cancelarOrdenesPendientes($t);

            return $this->cambiarEstado($t, EstadoTramite::Rechazado, $usuario, $motivo);
        });
    }

    /**
     * Adquisiciones que pasaron su fecha de caducidad sin concluir: deben volver a ingresarse (art. 34).
     * La ejecuta el programador de tareas; devuelve cuántas caducó.
     */
    public function caducarVencidos(?Carbon $hoy = null): int
    {
        $hoy ??= Carbon::today();
        $ids = Tramite::whereNotNull('caduca_en')
            ->where('caduca_en', '<', $hoy->toDateString())
            ->whereNotIn('estado', [EstadoTramite::Concluido->value, EstadoTramite::Rechazado->value, EstadoTramite::Caducado->value])
            ->pluck('id');

        $n = 0;
        foreach ($ids as $id) {
            $n += DB::transaction(function () use ($id): int {
                $t = Tramite::findOrFail($id);
                $vigente = DB::table('tramites')->where('id', $id)->lockForUpdate()->value('estado');
                if (EstadoTramite::from($vigente)->esTerminal()) {
                    return 0; // alguien lo cerró mientras tanto
                }
                $t->estado = EstadoTramite::from($vigente);
                $this->cancelarOrdenesPendientes($t);
                $this->cambiarEstado($t, EstadoTramite::Caducado, null, 'Caducó por no concluirse a tiempo; debe ingresarse de nuevo (art. 34).');

                return 1;
            });
        }

        return $n;
    }

    // ── Efectos de la conclusión ──────────────────────────────────────────────

    private function emitirTitulo(Tramite $t, OrigenTitulo $origen, ?Titulo $anterior): Titulo
    {
        $clave = $anterior?->regimen->clave ?? $t->dato('regimen_clave', 'perpetuidad');
        $regimen = Regimen::where('clave', $clave)->vigenteEn()->first()
            ?? throw TramiteException::por(Motivo::de("No hay una versión vigente del régimen «{$clave}».", 'Pide al administrador que revise los regímenes.'));

        $titulo = Titulo::create([
            'espacio_id' => $t->espacio_id,
            'regimen_id' => $regimen->id,
            'folio' => $this->folios->siguiente(Parametro::texto('serie_folio_titulo')),
            'expediente' => $t->folio,
            'origen' => $origen,
            'estado' => EstadoTitulo::Vigente,
            'fecha_emision' => Carbon::today(),
            'titulo_anterior_id' => $anterior?->id,
        ]);

        foreach ($t->participantesCon(ParteTramite::NuevoTitular)->values() as $i => $p) {
            $titulo->personas()->attach($p->persona_id, [
                'rol' => ($i === 0 ? RolTitularidad::Titular : RolTitularidad::Copropietario)->value,
                'orden' => $i + 1,
            ]);
        }

        $t->titulo_resultante_id = $titulo->id;

        return $titulo;
    }

    private function sustituirTitulo(Tramite $t): void
    {
        $anterior = Titulo::where('espacio_id', $t->espacio_id)->where('estado', EstadoTitulo::Vigente->value)
            ->lockForUpdate()->with('regimen')->firstOrFail();
        $anterior->update(['estado' => EstadoTitulo::Sustituido]);

        $esCesion = $t->dato('via') === 'cesion';
        $nuevo = $this->emitirTitulo($t, $esCesion ? OrigenTitulo::Cesion : OrigenTitulo::Herencia, $anterior);

        if ($esCesion) {
            foreach ($t->participantesCon(ParteTramite::NuevoTitular) as $p) {
                Cesion::create([
                    'espacio_id' => $t->espacio_id,
                    'titulo_anterior_id' => $anterior->id,
                    'titulo_nuevo_id' => $nuevo->id,
                    'cesionario_id' => $p->persona_id,
                    'fecha' => Carbon::today(),
                ]);
            }
        }
    }

    private function inhumar(Tramite $t, User $usuario): void
    {
        $espacio = Espacio::with('ultimoInhumado')->findOrFail($t->espacio_id);
        $ahora = Carbon::now();
        $ultimo = $espacio->ultimoInhumado;

        // Reutilización: los restos anteriores quedan al pie de la misma fosa (art. 44).
        if (! $espacio->es_fosa_comun && $ultimo !== null && $ultimo->estado === EstadoInhumado::Inhumado) {
            Movimiento::create([
                'inhumado_id' => $ultimo->id,
                'tramite_id' => $t->id,
                'tipo' => TipoMovimiento::Exhumacion,
                'fecha_hora' => $ahora,
                'espacio_origen_id' => $espacio->id,
                'causa' => 'Reutilización de la fosa; restos depositados al pie de la misma (arts. 44 y 105).',
                'solicitante_id' => $t->solicitante_id,
                'registrado_por' => $usuario->id,
            ]);
            $ultimo->update(['estado' => EstadoInhumado::RestosEnSitio]);
        }

        $f = $t->dato('finado');
        $inhumado = Inhumado::create([
            'espacio_id' => $espacio->id,
            'nombre' => $f['nombre'],
            'primer_apellido' => $f['primer_apellido'] ?? null,
            'segundo_apellido' => $f['segundo_apellido'] ?? null,
            'es_desconocido' => (bool) ($f['es_desconocido'] ?? false),
            'sexo' => $f['sexo'],
            'fecha_nacimiento' => $f['fecha_nacimiento'] ?? null,
            'fecha_defuncion' => $f['fecha_defuncion'],
            'edad_al_fallecer' => $f['edad_al_fallecer'] ?? null,
            'numero_acta_defuncion' => $f['numero_acta_defuncion'],
            'lugar_fallecimiento' => $f['lugar_fallecimiento'],
            'causa_fallecimiento' => $t->causa_fallecimiento,
            'fecha_inhumacion' => $ahora->toDateString(),
            'estado' => EstadoInhumado::Inhumado,
        ]);

        Movimiento::create([
            'inhumado_id' => $inhumado->id,
            'tramite_id' => $t->id,
            'tipo' => TipoMovimiento::Inhumacion,
            'fecha_hora' => $ahora,
            'espacio_destino_id' => $espacio->id,
            'solicitante_id' => $t->solicitante_id,
            'permiso_folio' => $t->dato('permiso_folio'),
            'permiso_fecha' => $t->dato('permiso_fecha'),
            'por_orden_autoridad' => $t->por_orden_autoridad,
            'registrado_por' => $usuario->id,
        ]);

        $t->inhumado_id = $inhumado->id;
    }

    private function exhumar(Tramite $t, User $usuario): void
    {
        $inhumado = Inhumado::lockForUpdate()->findOrFail($t->inhumado_id);
        $ahora = Carbon::now();
        $comun = [
            'inhumado_id' => $inhumado->id,
            'tramite_id' => $t->id,
            'solicitante_id' => $t->solicitante_id,
            'permiso_folio' => $t->dato('permiso_folio'),
            'permiso_fecha' => $t->dato('permiso_fecha'),
            'por_orden_autoridad' => $t->por_orden_autoridad,
            'registrado_por' => $usuario->id,
        ];

        Movimiento::create($comun + [
            'tipo' => TipoMovimiento::Exhumacion,
            'fecha_hora' => $ahora,
            'espacio_origen_id' => $inhumado->espacio_id,
            'causa' => $t->motivo_exhumacion->getLabel(),
        ]);

        match (DestinoRestos::from($t->dato('destino.tipo'))) {
            DestinoRestos::EnSitio => $inhumado->update(['estado' => EstadoInhumado::RestosEnSitio]),
            DestinoRestos::Traslado => (function () use ($comun, $ahora, $inhumado, $t): void {
                Movimiento::create($comun + [
                    'tipo' => TipoMovimiento::Traslado,
                    'fecha_hora' => $ahora,
                    'espacio_origen_id' => $inhumado->espacio_id,
                    'destino_externo' => $t->dato('destino.destino_externo'),
                    'realizado_por' => $t->dato('destino.realizado_por'),
                ]);
                $inhumado->update(['estado' => EstadoInhumado::Trasladado, 'espacio_id' => null]);
            })(),
            DestinoRestos::OtroEspacio => (function () use ($comun, $ahora, $inhumado, $t): void {
                $destino = (int) $t->dato('destino.espacio_destino_id');
                Movimiento::create($comun + [
                    'tipo' => TipoMovimiento::Reinhumacion,
                    'fecha_hora' => $ahora,
                    'espacio_origen_id' => $inhumado->espacio_id,
                    'espacio_destino_id' => $destino,
                ]);
                $inhumado->update(['estado' => EstadoInhumado::Inhumado, 'espacio_id' => $destino]);
            })(),
        };
    }

    // ── Pagos ─────────────────────────────────────────────────────────────────

    /** Una orden por concepto, con la cuota vigente congelada. Si falta una cuota, no emite ninguna. */
    private function emitirOrdenes(Tramite $t, User $usuario): void
    {
        $claves = match ($t->tipo) {
            TipoTramite::Adquisicion => ['titulo_adquisicion'],
            TipoTramite::Regularizacion => ['titulo_regularizacion'],
            TipoTramite::Inhumacion => ['inhumacion'],
            TipoTramite::Exhumacion => array_merge(['exhumacion'], match ($t->dato('destino.tipo')) {
                DestinoRestos::Traslado->value => ['traslado'],
                DestinoRestos::OtroEspacio->value => ['reinhumacion'],
                default => [],
            }),
            TipoTramite::Construccion => [$t->dato('concepto_clave')],
        };

        $seccion = $t->espacio()->with('lote.seccion')->first()->lote->seccion;
        $porEmitir = [];
        $faltantes = [];

        foreach ($claves as $clave) {
            $concepto = ConceptoCobro::where('clave', $clave)->first();
            $cuota = $concepto?->cuotaPara($concepto->por_seccion ? $seccion : null);
            if ($cuota === null) {
                $nombre = $concepto?->nombre ?? $clave;
                $faltantes[] = Motivo::de(
                    "Falta en el tabulador la cuota de «{$nombre}»".($concepto?->por_seccion ? " para la sección {$seccion->clave}" : '').', así que no se puede emitir la orden de pago.',
                    'Pide a Tesorería que la capture. El trámite queda en revisión y no pierde nada mientras tanto.',
                );

                continue;
            }
            $porEmitir[] = [$concepto, $cuota];
        }
        $this->exigirSinMotivos($faltantes);

        foreach ($porEmitir as [$concepto, $cuota]) {
            OrdenPago::create([
                'folio' => $this->folios->siguiente(Parametro::texto('serie_folio_orden_pago')),
                'tramite_id' => $t->id,
                'concepto_id' => $concepto->id,
                'cuota_id' => $cuota->id,
                'importe' => $cuota->importe,
                'estado' => EstadoOrdenPago::Emitida,
                'emitida_por' => $usuario->id,
            ]);
        }
    }

    private function cancelarOrdenesPendientes(Tramite $t): void
    {
        $t->ordenesPago()->where('estado', EstadoOrdenPago::Emitida->value)->get()
            ->each(fn (OrdenPago $o) => $o->update(['estado' => EstadoOrdenPago::Cancelada]));
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /**
     * Bloquea la fila del trámite hasta el fin de la transacción y confirma que siga en un estado esperado.
     * Debe llamarse al inicio de la transacción, antes de validar o escribir: así nadie más lo mueve en medio.
     */
    private function bloquear(Tramite $t, EstadoTramite ...$esperados): void
    {
        $vigente = EstadoTramite::from(DB::table('tramites')->where('id', $t->id)->lockForUpdate()->value('estado'));
        $t->refresh();

        if (! in_array($vigente, $esperados, true)) {
            throw TramiteException::por(Motivo::de(
                "El trámite {$t->folio} está «{$vigente->getLabel()}» y este paso no corresponde a ese estado.",
                'Puede que otra persona lo haya movido hace un momento. Vuelve a abrirlo para ver su estado actual.',
            ));
        }
    }

    /** Requiere que el llamador ya tenga el bloqueo de la fila (ver bloquear()). */
    private function cambiarEstado(Tramite $t, EstadoTramite $destino, ?User $usuario, ?string $comentario = null): Tramite
    {
        if (! $t->puedeTransicionarA($destino)) {
            throw TramiteException::por(Motivo::de("Un trámite «{$t->estado->getLabel()}» no puede pasar a «{$destino->getLabel()}».", 'Revisa el paso que sigue en el trámite.'));
        }

        $origen = $t->estado;
        $t->update(['estado' => $destino]);
        $this->historial($t, $origen, $destino, $usuario, $comentario);

        return $t;
    }

    private function historial(Tramite $t, ?EstadoTramite $de, EstadoTramite $a, ?User $usuario, ?string $comentario): void
    {
        TramiteHistorial::create([
            'tramite_id' => $t->id,
            'de_estado' => $de,
            'a_estado' => $a,
            'usuario_id' => $usuario?->id,
            'comentario' => $comentario,
        ]);
    }

    private function exigirPermiso(User $usuario, Permiso $permiso, string $accion): void
    {
        if (! $usuario->puede($permiso)) {
            throw TramiteException::por(Motivo::de(
                "Tu usuario no tiene permiso para {$accion}.",
                "Pide al administrador del sistema un rol con el permiso «{$permiso->getLabel()}».",
            ));
        }
    }

    /** El expediente (requisitos, personas, cotejo) se integra en captura o revisión. */
    private function exigirEditable(Tramite $t): void
    {
        $t->refresh();
        if (! in_array($t->estado, [EstadoTramite::Capturado, EstadoTramite::EnRevision], true)) {
            throw TramiteException::por(Motivo::de(
                "El expediente ya no se puede cambiar: el trámite está «{$t->estado->getLabel()}».",
                'Si hay un error, rechaza el trámite explicando el motivo y captúralo de nuevo.',
            ));
        }
    }

    /** Fotos, firmas y comparecencias se registran mientras el trámite no esté cerrado. */
    private function exigirAbierto(Tramite $t): void
    {
        $t->refresh();
        if ($t->estado->esTerminal()) {
            throw TramiteException::por(Motivo::de("El trámite ya está «{$t->estado->getLabel()}» y no admite cambios.", 'Consulta su historial si necesitas revisar algo.'));
        }
    }

    /** @param list<Motivo> $motivos */
    private function exigirSinMotivos(array $motivos): void
    {
        if ($motivos !== []) {
            $unicos = [];
            foreach ($motivos as $m) {
                $unicos[(string) $m] = $m;
            }
            throw new TramiteException(array_values($unicos));
        }
    }

    private function minuscula(string $texto): string
    {
        return mb_strtolower($texto);
    }
}
