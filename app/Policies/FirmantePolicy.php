<?php

namespace App\Policies;

use App\Enums\Permiso;

class FirmantePolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosFirmantes;
    }
}
