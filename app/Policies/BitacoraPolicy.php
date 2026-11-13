<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\Bitacora;
use App\Models\User;

/** La bitácora sólo se consulta. Nadie la edita ni la borra, tampoco el administrador. */
class BitacoraPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->puede(Permiso::BitacoraVer);
    }

    public function view(User $u, Bitacora $b): bool
    {
        return $u->puede(Permiso::BitacoraVer);
    }

    public function create(User $u): bool
    {
        return false;
    }

    public function update(User $u, Bitacora $b): bool
    {
        return false;
    }

    public function delete(User $u, Bitacora $b): bool
    {
        return false;
    }

    public function deleteAny(User $u): bool
    {
        return false;
    }
}
