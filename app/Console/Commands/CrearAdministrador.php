<?php

namespace App\Console\Commands;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Crea la primera cuenta de administración, o da ese rol a una cuenta existente.
 * Es el único paso de instalación que requiere la terminal; todo lo demás se configura en la interfaz.
 */
class CrearAdministrador extends Command
{
    protected $signature = 'panteones:crear-administrador {--email= : Correo de la cuenta}';

    protected $description = 'Crea la cuenta de administración del sistema o da ese rol a una cuenta existente';

    public function handle(): int
    {
        $rol = Rol::where('clave', 'administrador')->first();
        if ($rol === null) {
            $this->error('Falta el rol de administración. Ejecuta primero: php artisan db:seed --class=CatalogosSeeder');

            return self::FAILURE;
        }

        $email = $this->option('email') ?: text('Correo de la cuenta', required: true, validate: fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Escribe un correo válido, por ejemplo nombre@coatepec.gob.mx.');
        $usuario = User::where('email', $email)->first();

        if ($usuario === null) {
            $nombre = text('Nombre completo', required: true);
            $clave = password('Contraseña (mínimo 12 caracteres)', required: true, validate: fn ($v) => mb_strlen($v) < 12 ? 'Usa al menos 12 caracteres.' : null);
            $usuario = User::create(['name' => $nombre, 'email' => $email, 'password' => $clave, 'activo' => true]);
            $this->info("Cuenta creada: {$email}");
        }

        $usuario->update(['activo' => true]);
        $usuario->roles()->syncWithoutDetaching([$rol->id]);
        $this->info("{$usuario->name} ya puede administrar el sistema.");

        return self::SUCCESS;
    }
}
