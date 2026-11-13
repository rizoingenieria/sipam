<?php

namespace App\Filament\Resources\Usuarios\Pages;

use App\Filament\Resources\Usuarios\UsuarioResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUsuarios extends ManageRecords
{
    protected static string $resource = UsuarioResource::class;

    public function getSubheading(): ?string
    {
        return 'Quién entra al sistema y qué puede hacer. Las cuentas no se borran: se les quita el acceso y su historial se conserva.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo usuario')
                ->modalHeading('Nuevo usuario')
                ->modalSubmitActionLabel('Crear usuario')
                ->createAnother(false)
                ->using(fn (array $data, Action $action) => UsuarioResource::guardar(new User, $data, $action)),
        ];
    }
}
