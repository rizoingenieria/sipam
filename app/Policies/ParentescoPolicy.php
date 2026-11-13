<?php

namespace App\Policies;

use App\Enums\Permiso;

class ParentescoPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosReglas;
    }
}
