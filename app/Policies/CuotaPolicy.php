<?php

namespace App\Policies;

use App\Enums\Permiso;

class CuotaPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosTabulador;
    }
}
