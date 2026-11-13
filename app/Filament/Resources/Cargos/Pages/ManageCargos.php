<?php

namespace App\Filament\Resources\Cargos\Pages;

use App\Filament\Resources\Cargos\CargoResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCargos extends ManageRecords
{
    protected static string $resource = CargoResource::class;

    public function getSubheading(): ?string
    {
        return 'Cómo se llama cada cargo en los documentos. Quién lo ocupa se registra en Firmantes.';
    }
}
