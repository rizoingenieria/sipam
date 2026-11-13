<?php

namespace App\Policies;

use App\Enums\Permiso;

class ParametroPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosReglas;
    }
}
