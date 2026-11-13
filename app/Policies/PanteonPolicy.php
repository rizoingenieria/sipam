<?php

namespace App\Policies;

use App\Enums\Permiso;

class PanteonPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosInventario;
    }
}
