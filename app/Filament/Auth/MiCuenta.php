<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\EditProfile;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * «Mi cuenta»: cambiar la contraseña y configurar la verificación en dos pasos.
 * El nombre y el correo se muestran pero no se editan aquí: el correo es la identidad con la que se entra
 * y la asigna quien administra el sistema (Administración › Usuarios).
 */
class MiCuenta extends EditProfile
{
    public function getTitle(): string|Htmlable
    {
        return 'Mi cuenta';
    }

    public static function getLabel(): string
    {
        return 'Mi cuenta';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent()->disabled()->dehydrated(false)
                ->helperText('Si tu nombre o tu correo están mal, pide a quien administra el sistema que los corrija.'),
            $this->getEmailFormComponent()->disabled()->dehydrated(false),
            $this->getPasswordFormComponent()->label('Nueva contraseña')
                ->helperText('Déjala vacía si no quieres cambiarla. Al menos 12 caracteres.')
                ->rule('min:12'),
            $this->getPasswordConfirmationFormComponent()->label('Escribe otra vez la nueva contraseña'),
            $this->getCurrentPasswordFormComponent()->label('Tu contraseña actual'),
        ]);
    }
}
