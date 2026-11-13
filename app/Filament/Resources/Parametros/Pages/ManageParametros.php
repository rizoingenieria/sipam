<?php

namespace App\Filament\Resources\Parametros\Pages;

use App\Filament\Resources\Parametros\ParametroResource;
use Filament\Resources\Pages\ManageRecords;

class ManageParametros extends ManageRecords
{
    protected static string $resource = ParametroResource::class;

    public function getSubheading(): ?string
    {
        return 'Valores que fija el reglamento. Si una reforma los cambia, ajústalos aquí con la fecha en que aplican.';
    }
}
