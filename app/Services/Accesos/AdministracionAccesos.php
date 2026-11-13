<?php

namespace App\Services\Accesos;

use App\Enums\Permiso;
use App\Models\Rol;
use App\Models\User;
use App\Support\AccionBloqueada;
use App\Support\Motivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cambios de acceso con una sola regla de seguridad: siempre debe quedar al menos una persona
 * activa que pueda administrar usuarios. Sin ella, nadie podría corregir un error sin un programador.
 *
 * La interfaz guarda roles, permisos y usuarios a través de este servicio.
 */
final class AdministracionAccesos
{
    /** @param list<int> $rolIds */
    public function asignarRoles(User $usuario, array $rolIds, User $actor): void
    {
        $this->exigirAdministrador($actor);
        $this->conGuardia(fn () => $usuario->roles()->sync($rolIds), $usuario);
    }

    /** @param list<Permiso|string> $permisos */
    public function guardarPermisos(Rol $rol, array $permisos, User $actor): void
    {
        $this->exigirAdministrador($actor);
        $valores = array_map(fn ($p) => $p instanceof Permiso ? $p->value : $p, $permisos);
        $this->conGuardia(fn () => $rol->update(['permisos' => $valores]), $rol);
    }

    public function activarRol(Rol $rol, bool $activo, User $actor): void
    {
        $this->exigirAdministrador($actor);
        $this->conGuardia(fn () => $rol->update(['activo' => $activo]), $rol);
    }

    public function activarUsuario(User $usuario, bool $activo, User $actor): void
    {
        $this->exigirAdministrador($actor);
        $this->conGuardia(fn () => $usuario->update(['activo' => $activo]), $usuario);
    }

    public static function quedaAdministrador(): bool
    {
        return User::where('activo', true)
            ->whereHas('roles', fn ($q) => $q->where('roles.activo', true)->whereJsonContains('roles.permisos', Permiso::AdministrarUsuarios->value))
            ->exists();
    }

    /**
     * Aplica el cambio y lo deshace si deja el sistema sin administración. Al deshacerlo, también
     * recarga el registro en memoria: si no, la pantalla mostraría como guardado algo que no se guardó.
     */
    private function conGuardia(callable $cambio, Model $registro): void
    {
        try {
            DB::transaction(function () use ($cambio): void {
                $cambio();

                if (! self::quedaAdministrador()) {
                    throw AccionBloqueada::por(Motivo::de(
                        'Con este cambio nadie podría administrar usuarios.',
                        'Antes, da el permiso «Administrar usuarios y roles» a otra persona activa.',
                    ));
                }
            });
        } catch (\Throwable $e) {
            $registro->refresh();
            throw $e;
        }
    }

    /** Se consulta en la base, no en memoria: el actor puede ser el mismo registro que se está cambiando. */
    private function exigirAdministrador(User $actor): void
    {
        if (! User::find($actor->id)?->puede(Permiso::AdministrarUsuarios)) {
            throw AccionBloqueada::por(Motivo::de(
                'Tu usuario no puede administrar usuarios y roles.',
                'Pide este cambio a la persona que administra el sistema.',
            ));
        }
    }
}
