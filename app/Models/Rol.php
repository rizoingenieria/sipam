<?php

namespace App\Models;

use App\Enums\Permiso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Rol extends Model
{
    protected $table = 'roles';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'permisos', 'activo', 'exige_dos_pasos'];

    protected function casts(): array
    {
        return ['permisos' => 'array', 'activo' => 'boolean', 'exige_dos_pasos' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Sólo se guardan permisos que existen, sin repetir y en orden estable.
        static::saving(function (Rol $rol): void {
            $validos = array_map(fn (Permiso $p) => $p->value, Permiso::cases());
            $rol->permisos = array_values(array_intersect($validos, (array) $rol->permisos));
        });
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'rol_usuario');
    }

    public function tiene(Permiso $permiso): bool
    {
        return in_array($permiso->value, (array) $this->permisos, true);
    }
}
