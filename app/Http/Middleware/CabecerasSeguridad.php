<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad básicas: el sistema no se puede incrustar en otro sitio (clickjacking),
 * el navegador no adivina tipos de archivo y no se envía la dirección completa a sitios externos.
 */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);
        $respuesta->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');
        $respuesta->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $respuesta->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        if ($request->isSecure()) {
            $respuesta->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $respuesta;
    }
}
