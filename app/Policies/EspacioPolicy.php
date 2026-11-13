<?php

namespace App\Policies;

use App\Enums\Permiso;

class EspacioPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosInventario;
    }
}
