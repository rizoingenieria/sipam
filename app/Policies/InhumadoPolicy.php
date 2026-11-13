<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Inhumado;
use App\Models\User;

/** El registro de finados se consulta con «Consultar trámites» y se corrige con su propio permiso. Nunca se borra. */
class InhumadoPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->puede(Permiso::TramitesVer);
    }

    public function view(User $u, Inhumado $i): bool
    {
        return $u->puede(Permiso::TramitesVer);
    }

    public function create(User $u): bool
    {
        return false; // un finado se registra al concluir su inhumación
    }

    public function update(User $u, Inhumado $i): bool
    {
        return $u->puede(Permiso::InhumadosCorregir);
    }

    public function delete(User $u, Inhumado $i): bool
    {
        return false;
    }

    public function deleteAny(User $u): bool
    {
        return false;
    }
}
