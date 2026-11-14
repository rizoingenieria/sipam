<?php

namespace App\Policies;

use App\Enums\Permiso;
use App\Models\DocumentoAcervo;
use App\Models\User;

/** Acervo histórico: se consulta, se carga y se retira con permisos distintos. Nunca se borra. */
class DocumentoAcervoPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->puede(Permiso::AcervoConsultar);
    }

    public function view(User $u, DocumentoAcervo $d): bool
    {
        return $u->puede(Permiso::AcervoConsultar);
    }

    public function create(User $u): bool
    {
        return $u->puede(Permiso::AcervoCargar);
    }

    public function update(User $u, DocumentoAcervo $d): bool
    {
        return $u->puede(Permiso::AcervoCargar) && ! $d->retirado();
    }

    public function retirar(User $u, DocumentoAcervo $d): bool
    {
        return $u->puede(Permiso::AcervoRetirar) && ! $d->retirado();
    }

    public function delete(User $u, DocumentoAcervo $d): bool
    {
        return false;
    }

    public function deleteAny(User $u): bool
    {
        return false;
    }

    public function restore(User $u, DocumentoAcervo $d): bool
    {
        return false;
    }

    public function forceDelete(User $u, DocumentoAcervo $d): bool
    {
        return false;
    }

    public function replicate(User $u, DocumentoAcervo $d): bool
    {
        return false;
    }
}
