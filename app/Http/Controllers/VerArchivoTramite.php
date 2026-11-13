<?php

namespace App\Http\Controllers;

use App\Enums\Permiso;
use App\Models\TramiteArchivo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Muestra una evidencia del trámite. Los archivos viven en el disco privado: sólo se ven
 * con sesión y con permiso de consultar trámites, nunca por una liga pública.
 */
class VerArchivoTramite
{
    public function __invoke(TramiteArchivo $archivo): StreamedResponse
    {
        abort_unless(auth()->user()?->puede(Permiso::TramitesVer), 403);
        abort_unless(Storage::disk($archivo->disco)->exists($archivo->ruta), 404, 'El archivo ya no está en el servidor.');

        return Storage::disk($archivo->disco)->response($archivo->ruta, $archivo->nombre_original, [
            'Content-Type' => $archivo->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
