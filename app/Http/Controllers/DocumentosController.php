<?php

namespace App\Http\Controllers;

use App\Models\OrdenPago;
use App\Models\Titulo;
use App\Models\Tramite;
use App\Models\TramiteParticipante;
use App\Models\TramiteRequisito;
use App\Services\Documentos\Documento;
use App\Services\Documentos\DocumentosDelTramite;
use App\Services\Documentos\GeneradorDocumentos;
use App\Support\AccionBloqueada;
use Closure;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abre cada documento como PDF en una pestaña nueva, listo para imprimir. Sólo con sesión:
 * consultar trámites basta para todos, salvo el título, que pide permiso de emitirlo.
 */
class DocumentosController
{
    public function __construct(private readonly GeneradorDocumentos $documentos) {}

    public function orden(OrdenPago $orden): Response
    {
        return $this->abrir(fn () => $this->documentos->ordenPago($orden), 'orden-de-pago', $orden->folio);
    }

    public function paraFirmar(TramiteRequisito $fila): Response
    {
        return $this->abrir(fn () => $this->documentos->paraFirmar($fila), $fila->requisito->clave, $fila->tramite->folio);
    }

    public function consentimiento(TramiteParticipante $participante): Response
    {
        return $this->abrir(fn () => $this->documentos->consentimiento($participante), 'consentimiento', $participante->tramite->folio);
    }

    public function notificacion(Tramite $tramite): Response
    {
        return $this->abrir(fn () => $this->documentos->notificacion($tramite), 'aviso', $tramite->folio);
    }

    public function resolucion(Tramite $tramite): Response
    {
        return $this->abrir(fn () => $this->documentos->resolucion($tramite), 'resolucion', $tramite->folio);
    }

    public function titulo(Titulo $titulo): Response
    {
        abort_unless(DocumentosDelTramite::puedeImprimirTitulos(), 403, 'Tu usuario no tiene permiso para imprimir títulos.');

        return $this->abrir(fn () => $this->documentos->titulo($titulo), 'titulo', $titulo->folio);
    }

    /** @param  Closure(): Documento  $generar */
    private function abrir(Closure $generar, string $tipo, string $folio): Response
    {
        abort_unless(DocumentosDelTramite::puedeConsultar(), 403);

        try {
            $documento = $generar();
        } catch (AccionBloqueada $e) {
            abort(409, $e->motivos[0]->problema);
        }

        return $documento->pdf()->stream(GeneradorDocumentos::nombreArchivo($tipo, $folio))
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
