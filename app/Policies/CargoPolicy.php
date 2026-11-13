<?php

namespace App\Policies;

use App\Enums\Permiso;

class CargoPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosFirmantes;
    }
}
