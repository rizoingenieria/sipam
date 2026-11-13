<?php

namespace App\Services\Instalacion;

use App\Services\Diagnostico\DiagnosticoConfiguracion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Revisión de la instalación en el servidor: lo que debe estar bien antes de abrir el sistema al público,
 * y lo que conviene revisar cada semana (cron, respaldos). Cada punto dice qué hacer si no está bien.
 *
 * Estado: 'ok', 'aviso' (conviene corregirlo) o 'falla' (debe corregirse antes de operar).
 */
final class Verificacion
{
    /** @return list<array{punto: string, estado: string, detalle: string}> */
    public function revisar(): array
    {
        $r = [];
        $add = function (string $punto, string $estado, string $detalle) use (&$r): void {
            $r[] = compact('punto', 'estado', 'detalle');
        };

        // ── Aplicación ──
        $add('Modo de producción', app()->environment('production') ? 'ok' : 'falla',
            app()->environment('production') ? 'APP_ENV=production' : 'En .env pon APP_ENV=production.');
        $add('Errores ocultos al público', config('app.debug') ? 'falla' : 'ok',
            config('app.debug') ? 'En .env pon APP_DEBUG=false: con «true» cualquiera ve datos internos cuando hay un error.' : 'APP_DEBUG=false');
        $add('Llave de cifrado', filled(config('app.key')) ? 'ok' : 'falla',
            filled(config('app.key')) ? 'APP_KEY definida. Respáldala: sin ella no se leen las causas de fallecimiento.' : 'Ejecuta php artisan key:generate una sola vez.');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $add('Dirección segura (https)', $https ? 'ok' : 'aviso', $https ? (string) config('app.url') : 'Instala un certificado (Let\'s Encrypt es gratuito) y pon APP_URL=https://…');
        $add('Cookie de sesión sólo por https', ! $https || config('session.secure') ? 'ok' : 'aviso',
            ! $https || config('session.secure') ? 'Correcto.' : 'En .env pon SESSION_SECURE_COOKIE=true.');
        $add('Idioma', config('app.locale') === 'es' ? 'ok' : 'aviso', config('app.locale') === 'es' ? 'APP_LOCALE=es' : 'En .env pon APP_LOCALE=es.');

        // ── PHP ──
        $add('Versión de PHP', version_compare(PHP_VERSION, '8.4.0', '>=') ? 'ok' : 'falla', 'PHP '.PHP_VERSION.(version_compare(PHP_VERSION, '8.4.0', '>=') ? '' : '. Se requiere 8.4 o superior.'));
        $faltan = array_values(array_filter(['pdo_mysql', 'mbstring', 'intl', 'fileinfo', 'zip', 'xmlreader', 'dom', 'gd'], fn ($e) => ! extension_loaded($e)));
        $add('Extensiones de PHP', $faltan === [] ? 'ok' : (array_diff($faltan, ['gd']) === [] ? 'aviso' : 'falla'),
            $faltan === [] ? 'Todas instaladas.' : 'Faltan: '.implode(', ', $faltan).'. Pide al proveedor que las active'.(in_array('gd', $faltan, true) ? ' (sin gd, el escudo PNG no sale en los PDF).' : '.'));

        // ── Base de datos ──
        try {
            DB::connection()->getPdo();
            $pendientes = $this->migracionesPendientes();
            $add('Base de datos', $pendientes === 0 ? 'ok' : 'falla', $pendientes === 0 ? 'Conectada y al día.' : "Hay {$pendientes} migraciones pendientes: ejecuta php artisan migrate --force.");
            $add('Bitácora protegida en MySQL', $this->bitacoraProtegida() ? 'ok' : 'aviso', $this->bitacoraProtegida()
                ? 'El usuario de la aplicación no puede modificar ni borrar la bitácora.'
                : 'El usuario de MySQL de la aplicación puede modificar o borrar la bitácora. Quítale UPDATE y DELETE sobre la tabla «bitacora» (ver la guía).');
        } catch (Throwable $e) {
            $add('Base de datos', 'falla', 'No se pudo conectar: revisa DB_HOST, DB_DATABASE, DB_USERNAME y DB_PASSWORD en .env.');
        }

        // ── Archivos ──
        $add('Escudo y logotipo (storage:link)', is_link(public_path('storage')) || is_dir(public_path('storage')) ? 'ok' : 'falla',
            is_link(public_path('storage')) ? 'Enlace creado.' : 'Ejecuta php artisan storage:link.');
        $noEscribibles = array_values(array_filter([storage_path(), base_path('bootstrap/cache')], fn ($d) => ! is_writable($d)));
        $add('Carpetas con permiso de escritura', $noEscribibles === [] ? 'ok' : 'falla',
            $noEscribibles === [] ? 'storage y bootstrap/cache.' : 'El servidor web no puede escribir en: '.implode(', ', $noEscribibles).'.');

        // ── Tareas programadas y respaldos ──
        $latido = Cache::get('panteones:latido');
        $vivo = $latido && Carbon::parse($latido)->gt(now()->subMinutes(15));
        $add('Tareas programadas (cron)', $vivo ? 'ok' : 'falla', $vivo
            ? 'Corriendo. Última señal: '.Carbon::parse($latido)->diffForHumans().'.'
            : 'El cron no está corriendo: sin él no caducan las adquisiciones ni se hacen respaldos. Agrega la línea de la guía al crontab.');
        $mysqldump = Process::run('command -v '.escapeshellarg((string) config('respaldos.mysqldump')))->successful();
        $add('Programa de respaldos (mysqldump)', $mysqldump ? 'ok' : 'falla', $mysqldump ? 'Disponible.' : 'No se encontró mysqldump. Instálalo o pon su ruta en RESPALDOS_MYSQLDUMP.');
        $ultimo = collect(File::isDirectory(config('respaldos.ruta')) ? File::directories(config('respaldos.ruta')) : [])
            ->filter(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', basename($d)) && is_file($d.'/base.sql.gz') && filesize($d.'/base.sql.gz') >= 100)
            ->map(fn ($d) => basename($d))->sort()->last();
        $reciente = $ultimo && Carbon::createFromFormat('Y-m-d_His', $ultimo)->gt(now()->subHours(36));
        $add('Respaldo reciente', $reciente ? 'ok' : 'aviso', $reciente
            ? "Último respaldo: {$ultimo}. Copia los respaldos a otro equipo al menos cada semana."
            : ($ultimo ? "El último respaldo es de {$ultimo}. Revisa el cron y php artisan panteones:respaldar." : 'Aún no hay respaldos. Ejecuta php artisan panteones:respaldar para probar.'));

        // ── Configuración del sistema ──
        $bloqueantes = app(DiagnosticoConfiguracion::class)->revisar()->filter(fn ($p) => $p->esBloqueante())->count();
        $add('Configuración del sistema', $bloqueantes === 0 ? 'ok' : 'falla', $bloqueantes === 0
            ? 'Sin pendientes que impidan operar. Revisa la lista de arranque en Inicio.'
            : "Hay {$bloqueantes} pendientes que impiden operar. Ejecuta php artisan panteones:diagnostico.");

        return $r;
    }

    private function migracionesPendientes(): int
    {
        $migrador = app('migrator');
        if (! $migrador->repositoryExists()) {
            return count($migrador->getMigrationFiles(database_path('migrations')));
        }
        $corridas = $migrador->getRepository()->getRan();

        return count(array_diff(array_keys($migrador->getMigrationFiles(database_path('migrations'))), $corridas));
    }

    /** ¿El usuario de MySQL de la aplicación NO puede hacer UPDATE ni DELETE en la bitácora? */
    private function bitacoraProtegida(): bool
    {
        try {
            $permisos = collect(DB::select('SHOW GRANTS FOR CURRENT_USER()'))->map(fn ($g) => strtoupper((string) array_values((array) $g)[0]));
        } catch (Throwable) {
            return false;
        }
        $bd = strtoupper((string) DB::connection()->getDatabaseName());

        return ! $permisos->contains(function (string $g) use ($bd): bool {
            $alcance = str_contains($g, ' ON *.* ') || str_contains($g, " ON `{$bd}`.* ") || str_contains($g, " ON `{$bd}`.`BITACORA` ");

            return $alcance && (str_contains($g, 'ALL PRIVILEGES') || preg_match('/\b(UPDATE|DELETE)\b/', $g));
        });
    }
}
