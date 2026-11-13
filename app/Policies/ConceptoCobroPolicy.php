<?php

namespace App\Policies;

use App\Enums\Permiso;

class ConceptoCobroPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosTabulador;
    }
}
