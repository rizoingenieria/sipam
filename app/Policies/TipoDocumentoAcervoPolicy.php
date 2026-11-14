<?php

namespace App\Policies;

use App\Enums\Permiso;

/** Tipos de documento del acervo: los edita quien configura el reglamento; no se borran, se desactivan. */
class TipoDocumentoAcervoPolicy extends PoliticaPorPermiso
{
    protected function permiso(): Permiso
    {
        return Permiso::CatalogosReglas;
    }
}
