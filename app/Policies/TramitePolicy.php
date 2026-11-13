<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Tramite;
use App\Models\User;

/**
 * Ver un trámite pide «Consultar trámites»; capturarlo, «Capturar trámites». Nada se edita ni se borra
 * desde formularios: cada cambio es un paso del GestorTramites, que revisa su propio permiso.
 */
class TramitePolicy
{
    public function viewAny(User $u): bool
    {
        return $u->puede(Permiso::TramitesVer);
    }

    public function view(User $u, Tramite $t): bool
    {
        return $u->puede(Permiso::TramitesVer);
    }

    public function create(User $u): bool
    {
        return $u->puede(Permiso::TramitesCapturar);
    }

    public function update(User $u, Tramite $t): bool
    {
        return false;
    }

    public function delete(User $u, Tramite $t): bool
    {
        return false;
    }

    public function deleteAny(User $u): bool
    {
        return false;
    }
}
