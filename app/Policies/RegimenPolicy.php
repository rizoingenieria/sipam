<?php

namespace App\Policies;

use App\Enums\Permiso;

class RegimenPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosReglas;
    }
}
