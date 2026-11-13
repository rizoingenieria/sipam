<?php

namespace App\Policies;

use App\Enums\Permiso;

class UserPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::AdministrarUsuarios;
    }
}
