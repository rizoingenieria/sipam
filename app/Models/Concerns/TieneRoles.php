<?php

namespace App\Models\Concerns;

use App\Enums\Permiso;
use App\Models\Rol;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Roles y permisos del usuario. Un usuario inactivo no puede nada.
 * La consulta va a la base cada vez: un cambio de roles aplica de inmediato, sin cachés que invalidar.
 */
trait TieneRoles
{
    /**
     * Un usuario nuevo nace activo. Sin esto, un usuario recién creado no tiene «activo» en memoria
     * (la base sí lo pone en 1) y se le negaría todo hasta recargarlo.
     */
    public function initializeTieneRoles(): void
    {
        if (! array_key_exists('activo', $this->attributes)) {
            $this->attributes['activo'] = true;
        }
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'rol_usuario');
    }

    public function puede(Permiso $permiso): bool
    {
        return (bool) $this->activo
            && $this->roles()->where('roles.activo', true)->whereJsonContains('roles.permisos', $permiso->value)->exists();
    }

    /** @return Collection<int, Permiso> */
    public function permisos(): Collection
    {
        if (! $this->activo) {
            return collect();
        }

        return $this->roles()->where('roles.activo', true)->pluck('permisos')
            ->flatten()->unique()->map(fn (string $v) => Permiso::tryFrom($v))->filter()->values();
    }
}
