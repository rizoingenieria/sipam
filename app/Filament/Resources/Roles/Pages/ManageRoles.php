<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RolResource;
use App\Models\Rol;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRoles extends ManageRecords
{
    protected static string $resource = RolResource::class;

    public function getSubheading(): ?string
    {
        return 'Cada rol es un conjunto de permisos. Ajusta los roles a cómo trabaja tu ayuntamiento; los cambios aplican de inmediato.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo rol')
                ->modalHeading('Nuevo rol')
                ->modalSubmitActionLabel('Crear rol')
                ->modalWidth('4xl')
                ->createAnother(false)
                ->using(fn (array $data, Action $action) => RolResource::guardar(new Rol, $data, $action)),
        ];
    }
}
