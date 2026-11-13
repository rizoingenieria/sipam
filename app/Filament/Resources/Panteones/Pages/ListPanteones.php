<?php

namespace App\Filament\Resources\Panteones\Pages;

use App\Filament\Resources\Panteones\PanteonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPanteones extends ListRecords
{
    protected static string $resource = PanteonResource::class;

    public function getSubheading(): ?string
    {
        return 'Los panteones del municipio. Abre uno para ver y agregar sus secciones, lotes y espacios.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo panteón')->modalHeading('Nuevo panteón')
                ->modalSubmitActionLabel('Guardar panteón')->createAnother(false),
        ];
    }
}
