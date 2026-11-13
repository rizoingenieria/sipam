<?php

namespace App\Filament\Resources\Panteones\Pages;

use App\Filament\Resources\Panteones\PanteonResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditPanteon extends EditRecord
{
    protected static string $resource = PanteonResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->nombre;
    }

    public function getSubheading(): ?string
    {
        return 'Datos del panteón y sus secciones. Para dar de alta muchos lotes a la vez, usa «Generar lotes» en cada sección.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Guardar cambios');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Panteón guardado';
    }
}
