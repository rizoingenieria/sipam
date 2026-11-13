<?php

namespace App\Listeners;

use App\Enums\EventoBitacora;
use App\Models\Bitacora;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/** Entradas, salidas e intentos fallidos: sirven para detectar cuentas compartidas o ataques. */
class AnotarAccesos
{
    public function entrada(Login $e): void
    {
        Bitacora::anotar(EventoBitacora::Entrada, 'Usuario: '.$e->user->name, $e->user, usuarioId: $e->user->getAuthIdentifier());
    }

    public function salida(Logout $e): void
    {
        if ($e->user) {
            Bitacora::anotar(EventoBitacora::Salida, 'Usuario: '.$e->user->name, $e->user, usuarioId: $e->user->getAuthIdentifier());
        }
    }

    public function fallida(Failed $e): void
    {
        // Sólo el correo que se intentó; nunca la contraseña.
        Bitacora::anotar(EventoBitacora::EntradaFallida, 'Correo: '.mb_substr((string) ($e->credentials['email'] ?? '—'), 0, 150), $e->user, usuarioId: $e->user?->getAuthIdentifier());
    }
}
