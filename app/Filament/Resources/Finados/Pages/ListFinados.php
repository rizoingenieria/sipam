<?php

namespace App\Filament\Resources\Finados\Pages;

use App\Filament\Resources\Finados\FinadoResource;
use Filament\Resources\Pages\ListRecords;

class ListFinados extends ListRecords
{
    protected static string $resource = FinadoResource::class;

    public function getSubheading(): ?string
    {
        return 'Busca a una persona por su nombre o por el número de su acta de defunción.';
    }
}
