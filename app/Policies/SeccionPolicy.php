<?php

namespace App\Policies;

use App\Enums\Permiso;

class SeccionPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosInventario;
    }
}
