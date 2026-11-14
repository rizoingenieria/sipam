<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Verificación en dos pasos según el rol.
 * Filament sólo sabe exigirla a todos o a nadie; aquí se exige a quien tenga un rol activo marcado con
 * «Pide verificación en dos pasos». A esa persona, si aún no la activa, se le lleva a la pantalla para activarla.
 * Se aplica a todas las páginas del panel y a los documentos, reportes y archivos.
 */
class ExigirDosPasos
{
    public function handle(Request $request, Closure $next): mixed
    {
        $usuario = Filament::auth()->user();

        if (! $usuario instanceof User || ! $usuario->exigeDosPasos()) {
            return $next($request);
        }

        return app(EnsureMultiFactorAuthenticationIsEnabled::class)->handle($request, $next);
    }
}
