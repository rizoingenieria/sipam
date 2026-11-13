<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Política común: quien tiene el permiso del catálogo lo ve y lo edita. Nadie borra desde la interfaz:
 * los catálogos se versionan o se desactivan, y el inventario tiene historia que no debe perderse.
 * Filament oculta el menú de quien no tiene el permiso.
 */
abstract class PoliticaPorPermiso
{
    abstract protected function permiso(): Permiso;

    public function viewAny(User $u): bool
    {
        return $u->puede($this->permiso());
    }

    public function view(User $u, Model $m): bool
    {
        return $u->puede($this->permiso());
    }

    public function create(User $u): bool
    {
        return $u->puede($this->permiso());
    }

    public function update(User $u, Model $m): bool
    {
        return $u->puede($this->permiso());
    }

    public function delete(User $u, Model $m): bool
    {
        return false;
    }

    public function deleteAny(User $u): bool
    {
        return false;
    }

    public function restore(User $u, Model $m): bool
    {
        return false;
    }

    public function forceDelete(User $u, Model $m): bool
    {
        return false;
    }

    public function replicate(User $u, Model $m): bool
    {
        return false;
    }

    public function reorder(User $u): bool
    {
        return false;
    }
}
