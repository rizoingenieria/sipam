<?php

namespace App\Services\Documentos;

use App\Enums\EstadoOrdenPago;
use App\Enums\EstadoTitulo;
use App\Enums\EstadoTramite;
use App\Enums\ParteTramite;
use App\Enums\TipoTramite;
use App\Models\Cargo;
use App\Models\EntePublico;
use App\Models\OrdenPago;
use App\Models\Titulo;
use App\Models\Tramite;
use App\Models\TramiteParticipante;
use App\Models\TramiteRequisito;
use App\Support\AccionBloqueada;
use App\Support\EnLetra;
use App\Support\Motivo;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Documentos oficiales del panteón en PDF. Cada uno se arma con los datos guardados, así que
 * reimprimirlo da el mismo documento; si ya no vale (orden pagada, título sustituido), lleva un sello.
 * Los firmantes son los que ocupaban el cargo en la fecha del documento (catálogo de Firmantes).
 */
final class GeneradorDocumentos
{
    /** Orden de pago para llevar a Tesorería (arts. 65, 69 y 70). */
    public function ordenPago(OrdenPago $orden): Documento
    {
        $orden->loadMissing(['tramite.espacio.lote.seccion.panteon', 'tramite.solicitante', 'concepto', 'cuota']);
        $fecha = $orden->created_at;

        return $this->pdf('pdf.orden-pago', 'Orden de pago', $orden->folio, $fecha, [
            'orden' => $orden,
            't' => $orden->tramite,
            'conLetra' => EnLetra::pesos($orden->importe),
            'sello' => match ($orden->estado) {
                EstadoOrdenPago::Pagada => 'PAGADA',
                EstadoOrdenPago::Cancelada => 'CANCELADA',
                default => null,
            },
            'fundamento' => 'Arts. 65, 69 y 70'.($orden->cuota?->fundamento ? '; '.$orden->cuota->fundamento : '').'. Cuota aprobada en '.$orden->cuota?->acuerdo_cabildo,
            'firmas' => [$this->firma('encargado_panteones', $fecha)],
        ]);
    }

    /** Documentos que firma el ayuntamiento y se suben al expediente: responsiva y carta compromiso. */
    public function paraFirmar(TramiteRequisito $fila): Documento
    {
        $fila->loadMissing(['requisito', 'tramite.espacio.lote.seccion.panteon', 'tramite.solicitante']);
        $t = $fila->tramite;

        return match ($fila->requisito->clave) {
            'responsiva_encargado' => $this->pdf('pdf.responsiva', 'Responsiva', $t->folio, Carbon::now(), [
                't' => $t,
                'fundamento' => 'Art. 33, fr. VII, y art. 34',
                'firmas' => [$this->firma('encargado_panteones')],
            ]),
            'carta_compromiso' => $this->pdf('pdf.carta-compromiso', 'Carta compromiso', $t->folio, Carbon::now(), [
                't' => $t,
                'constructor' => $t->participantesCon(ParteTramite::Constructor)->first()?->persona,
                'fundamento' => 'Art. 21, fr. XXVI; arts. 31, 52 y 56',
                'firmas' => [
                    ['nombre' => $t->participantesCon(ParteTramite::Constructor)->first()?->persona->nombre_completo, 'cargo' => 'Responsable de la obra'],
                    ['nombre' => $t->solicitante->nombre_completo, 'cargo' => 'Solicitante'],
                    ['cargo' => 'Vo. Bo. Encargado de Panteones'] + $this->firma('encargado_panteones'),
                ],
            ]),
            default => throw AccionBloqueada::por(Motivo::de("«{$fila->requisito->documento}» no tiene formato para imprimir.", 'Usa el formato que ya tiene el ayuntamiento y súbelo firmado.')),
        };
    }

    /** Consentimiento firmado de quien autoriza una inhumación o exhumación (hojas de requisitos). */
    public function consentimiento(TramiteParticipante $p): Documento
    {
        $p->loadMissing(['persona', 'parentesco', 'tramite.espacio.lote.seccion.panteon', 'tramite.inhumado', 'tramite.espacio.tituloVigente.titulares']);
        $t = $p->tramite;
        if ($p->papel !== ParteTramite::Autorizante || ! in_array($t->tipo, [TipoTramite::Inhumacion, TipoTramite::Exhumacion], true)) {
            throw AccionBloqueada::por(Motivo::de('El consentimiento sólo lo firma quien autoriza una inhumación o exhumación.'));
        }
        $esTitular = $t->espacio->tituloVigente?->titulares->contains('id', $p->persona_id) ?? false;

        return $this->pdf('pdf.consentimiento', 'Consentimiento', $t->folio, Carbon::now(), [
            't' => $t,
            'p' => $p,
            'caracter' => $esTitular ? 'titular del derecho de uso del espacio' : ($p->parentesco ? mb_strtolower($p->parentesco->nombre).' de la persona finada' : 'responsable de la autorización'),
            'finado' => $t->tipo === TipoTramite::Inhumacion
                ? trim($t->dato('finado.nombre').' '.$t->dato('finado.primer_apellido').' '.$t->dato('finado.segundo_apellido'))
                : $t->inhumado?->nombre_completo,
            'fundamento' => $t->tipo === TipoTramite::Inhumacion ? 'Arts. 36 y 37' : 'Arts. 38 a 42',
            'firmas' => [['nombre' => $p->persona->nombre_completo, 'cargo' => 'Firma de quien autoriza'], $this->firma('encargado_panteones')],
        ]);
    }

    /** Notificación de la adquisición para estrados y para el lote (art. 34). Letra grande: se pega en la pared. */
    public function notificacion(Tramite $t): Documento
    {
        $t->loadMissing(['espacio.lote.seccion.panteon', 'solicitante']);
        if ($t->tipo !== TipoTramite::Adquisicion) {
            throw AccionBloqueada::por(Motivo::de('Sólo la adquisición se notifica en estrados.', null, 'Art. 34'));
        }
        $fecha = $t->publicado_en ?? Carbon::now();

        return $this->pdf('pdf.notificacion', 'Aviso', $t->folio, $fecha, [
            't' => $t,
            'area' => 'Secretaría del Ayuntamiento',
            'fundamento' => 'Arts. 24, 33 y 34',
            'firmas' => [$this->firma('secretario_ayuntamiento', $fecha)],
        ]);
    }

    /** Título oficial de derecho de uso con los datos del art. 25 y la firma del Secretario. */
    public function titulo(Titulo $titulo): Documento
    {
        $titulo->loadMissing(['espacio.lote.seccion.panteon', 'regimen', 'titulares', 'beneficiarios', 'tituloAnterior']);
        $fecha = $titulo->fecha_emision;

        return $this->pdf('pdf.titulo', 'Título', $titulo->folio, $fecha, [
            'titulo' => $titulo,
            'fechaLetra' => EnLetra::fecha($fecha),
            'area' => 'Secretaría del Ayuntamiento',
            'sello' => $titulo->estado === EstadoTitulo::Vigente ? null : 'SIN VALIDEZ',
            'fundamento' => 'Arts. 24 y 25'.($titulo->tituloAnterior ? '; sustituye al título '.$titulo->tituloAnterior->folio : ''),
            'firmas' => [$this->firma('secretario_ayuntamiento', $fecha), $this->firma('edil_ramo', $fecha)],
        ]);
    }

    /** Autorización de inhumación, exhumación u obra, o resolución de improcedencia. */
    public function resolucion(Tramite $t): Documento
    {
        $t->loadMissing(['espacio.lote.seccion.panteon', 'solicitante', 'inhumado', 'resueltoPor']);
        $concluido = $t->estado === EstadoTramite::Concluido;
        if (! $concluido && $t->estado !== EstadoTramite::Rechazado) {
            throw AccionBloqueada::por(Motivo::de('El trámite aún no tiene resolución.', 'Se imprime cuando se autoriza o se rechaza.'));
        }
        if ($concluido && in_array($t->tipo, [TipoTramite::Adquisicion, TipoTramite::Regularizacion], true)) {
            throw AccionBloqueada::por(Motivo::de('La resolución de este trámite es el título.', 'Imprímelo desde la ficha del título.'));
        }
        $fecha = $t->resuelto_en ?? Carbon::now();
        $secretaria = in_array($t->tipo, [TipoTramite::Adquisicion, TipoTramite::Regularizacion], true);

        return $this->pdf('pdf.resolucion', $concluido ? 'Autorización' : 'Resolución', $t->folio, $fecha, [
            't' => $t,
            'concluido' => $concluido,
            'encabezado' => $concluido ? match ($t->tipo) {
                TipoTramite::Inhumacion => 'Autorización de inhumación',
                TipoTramite::Exhumacion => 'Autorización de exhumación',
                default => 'Permiso de construcción o remodelación',
            } : 'Resolución de improcedencia',
            'fundamento' => match ($t->tipo) {
                TipoTramite::Inhumacion => 'Art. 21, frs. III y VI; arts. 36, 37 y 46',
                TipoTramite::Exhumacion => 'Art. 21, frs. III y VI; arts. 38 a 44 y 68',
                TipoTramite::Construccion => 'Arts. 51 a 59',
                default => $t->tipo->fundamento(),
            },
            'firmas' => [$this->firma($secretaria ? 'secretario_ayuntamiento' : 'encargado_panteones', $fecha)],
        ]);
    }

    public static function nombreArchivo(string $tipo, string $folio): string
    {
        return str($tipo)->slug().'-'.str($folio)->slug().'.pdf';
    }

    /** @return array{nombre: ?string, cargo: string} */
    public function firma(string $cargo, ?CarbonInterface $fecha = null): array
    {
        $c = Cargo::where('clave', $cargo)->first();
        $f = $c?->firmanteEn($fecha);

        return ['nombre' => $f?->nombre_para_firma, 'cargo' => $c?->nombre ?? $cargo];
    }

    /** Documento con el membrete del ayuntamiento. Lo usan también los reportes. */
    public function documento(string $vista, string $titulo, ?string $folio, CarbonInterface $fecha, array $datos, bool $horizontal = false): Documento
    {
        return $this->pdf($vista, $titulo, $folio, $fecha, $datos, $horizontal);
    }

    private function pdf(string $vista, string $titulo, ?string $folio, CarbonInterface $fecha, array $datos, bool $horizontal = false): Documento
    {
        $ente = EntePublico::actual();

        return new Documento($vista, $folio, horizontal: $horizontal, datos: $datos + [
            'ente' => $ente,
            'escudo' => $this->escudo($ente),
            'nombreDocumento' => mb_strtoupper($titulo),
            'folio' => $folio,
            'lugarFecha' => ($ente?->municipio ? "{$ente->municipio}, " : '').EnLetra::fecha($fecha),
        ]);
    }

    /** El escudo como data URI: no depende de rutas públicas ni del chroot de dompdf. */
    private function escudo(?EntePublico $ente): ?string
    {
        $ruta = $ente?->escudo_path ?: $ente?->logo_path;
        if (! $ruta || ! Storage::disk('public')->exists($ruta)) {
            return null;
        }
        $mime = Storage::disk('public')->mimeType($ruta);

        return in_array($mime, ['image/png', 'image/jpeg'], true)
            ? 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($ruta))
            : null;
    }
}
