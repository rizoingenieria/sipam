<?php

namespace App\Http\Controllers;

use App\Enums\Permiso;
use App\Models\ArchivoAcervo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entrega un archivo del acervo histórico: sólo con sesión y permiso de consulta, nunca por liga pública.
 * PDF e imágenes se abren en el navegador; TIFF (que los navegadores no muestran) y ?descargar=1 se descargan.
 * Lo que sale es exactamente el original guardado, con su huella en una cabecera para cotejarla.
 */
class VerArchivoAcervo
{
    public function __invoke(ArchivoAcervo $archivo): StreamedResponse
    {
        abort_unless(auth()->user()?->puede(Permiso::AcervoConsultar), 403);
        abort_unless(Storage::disk($archivo->disco)->exists($archivo->ruta), 404, 'El archivo ya no está en el servidor. Avisa a soporte técnico para recuperarlo del respaldo.');

        $archivo->loadMissing('documento');
        $nombre = $archivo->documento->folio.'-'.sprintf('%03d', $archivo->orden).'.'.pathinfo($archivo->ruta, PATHINFO_EXTENSION);
        $enLinea = ! request()->boolean('descargar') && ($archivo->esPdf() || $archivo->esImagenVisible());

        return Storage::disk($archivo->disco)->response($archivo->ruta, $nombre, [
            'Content-Type' => $archivo->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'X-Sha256' => $archivo->sha256,
        ], $enLinea ? 'inline' : 'attachment');
    }
}
