<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\TieneRoles;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'activo'])]
#[Hidden(['password', 'remember_token'])]
/**
 * Cuenta de acceso. La verificación en dos pasos (secreto y códigos de recuperación) se guarda cifrada con APP_KEY
 * y oculta en cualquier serialización; la bitácora la anota como «(dato reservado)».
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable, TieneRoles;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    /** Entra al sistema quien está activo y tiene al menos un rol activo. */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->activo && $this->roles()->where('roles.activo', true)->exists();
    }

    /** Debe usar verificación en dos pasos si alguno de sus roles activos lo pide (Roles y permisos). */
    public function exigeDosPasos(): bool
    {
        return $this->roles()->where('roles.activo', true)->where('roles.exige_dos_pasos', true)->exists();
    }

    public function tieneDosPasos(): bool
    {
        return filled($this->getAppAuthenticationSecret());
    }

    /** Para quien perdió el celular: el administrador la quita y la persona la vuelve a configurar al entrar. */
    public function quitarDosPasos(): void
    {
        $this->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
    }
}
