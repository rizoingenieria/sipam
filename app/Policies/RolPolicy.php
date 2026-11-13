<?php

namespace App\Policies;

use App\Enums\Permiso;

class RolPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::AdministrarUsuarios;
    }
}
