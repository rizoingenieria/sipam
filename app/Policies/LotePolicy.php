<?php

namespace App\Policies;

use App\Enums\Permiso;

class LotePolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosInventario;
    }
}
