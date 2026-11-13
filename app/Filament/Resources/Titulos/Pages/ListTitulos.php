<?php

namespace App\Filament\Resources\Titulos\Pages;

use App\Filament\Resources\Titulos\TituloResource;
use Filament\Resources\Pages\ListRecords;

class ListTitulos extends ListRecords
{
    protected static string $resource = TituloResource::class;

    public function getSubheading(): ?string
    {
        return 'Busca por folio, por el nombre del titular o por la clave del espacio. Los títulos se emiten al concluir una adquisición o un cambio de propietario.';
    }
}
