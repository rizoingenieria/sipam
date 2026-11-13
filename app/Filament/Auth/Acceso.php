<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Pantalla de acceso de SIPAM: el logo arriba a la izquierda, la foto del panteón de fondo
 * y una tarjeta con dos campos y un botón. Nada más que leer o decidir.
 *
 * - Las etiquetas quedan sólo para lectores de pantalla; el ícono y el texto de ejemplo dicen qué va.
 * - Sin «mantener la sesión abierta»: en ventanilla la computadora es compartida.
 * - El fondo y el logo los pone la vista filament/acceso/marca (gancho del panel), los estilos sipam.css.
 */
class Acceso extends Login
{
    public function hasLogo(): bool
    {
        return false; // el logo grande va fuera de la tarjeta
    }

    public function getHeading(): string|Htmlable|null
    {
        // Se conserva para lectores de pantalla; sipam.css lo oculta a la vista.
        return filled($this->userUndertakingMultiFactorAuthentication) ? parent::getHeading() : 'Iniciar sesión en SIPAM';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->hiddenLabel()
            ->placeholder('Usuario (tu correo)')
            ->prefixIcon(Heroicon::OutlinedUser, isInline: true)
            ->prefixIconColor('primary');
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->hiddenLabel()
            ->hint(null)
            ->placeholder('Contraseña')
            ->prefixIcon(Heroicon::OutlinedLockClosed, isInline: true)
            ->prefixIconColor('primary')
            ->revealable();
    }
}
