<?php

namespace App\Policies;

use App\Enums\Permiso;

class ReglaPlazoPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosReglas;
    }
}
