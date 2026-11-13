<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Titulo;
use App\Models\User;

/** Los títulos se consultan; se emiten y sustituyen sólo al concluir un trámite. */
class TituloPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->puede(Permiso::TramitesVer);
    }

    public function view(User $u, Titulo $t): bool
    {
        return $u->puede(Permiso::TramitesVer);
    }

    public function create(User $u): bool
    {
        return false;
    }

    public function update(User $u, Titulo $t): bool
    {
        return false;
    }

    public function delete(User $u, Titulo $t): bool
    {
        return false;
    }

    public function deleteAny(User $u): bool
    {
        return false;
    }
}
