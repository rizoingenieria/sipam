<?php

namespace App\Console\Commands;

use App\Enums\EventoBitacora;
use App\Models\Bitacora;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Respaldo diario: la base de datos (mysqldump consistente, sin bloquear a nadie) y los archivos de los
 * trámites y del escudo. Conserva los últimos N días. La contraseña de MySQL viaja en una variable de
 * entorno del proceso, no en la línea de comandos (no se ve con «ps»).
 */
class Respaldar extends Command
{
    protected $signature = 'panteones:respaldar {--sin-archivos : Sólo la base de datos}';

    protected $description = 'Respalda la base de datos y los archivos, y borra los respaldos más antiguos que el límite';

    public function handle(): int
    {
        $raiz = rtrim(config('respaldos.ruta'), '/');
        $carpeta = $raiz.'/'.now()->format('Y-m-d_His');
        File::ensureDirectoryExists($carpeta, 0750);
        $db = config('database.connections.'.config('database.default'));

        // Primero a un archivo y luego se comprime: así un error de mysqldump no queda escondido tras «gzip».
        $volcado = Process::env(['MYSQL_PWD' => (string) ($db['password'] ?? '')])
            ->timeout(3600)
            ->run(sprintf('%s --single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4 -h %s -P %s -u %s --result-file=%s %s',
                escapeshellcmd(config('respaldos.mysqldump')), escapeshellarg((string) $db['host']), escapeshellarg((string) $db['port']),
                escapeshellarg((string) $db['username']), escapeshellarg($carpeta.'/base.sql'), escapeshellarg((string) $db['database'])));
        $comprimido = $volcado->successful() ? Process::timeout(3600)->run('gzip -f '.escapeshellarg($carpeta.'/base.sql')) : null;
        if (! $volcado->successful() || ! $comprimido?->successful() || ! is_file($carpeta.'/base.sql.gz') || filesize($carpeta.'/base.sql.gz') < 100) {
            $error = trim($volcado->errorOutput()) ?: trim((string) $comprimido?->errorOutput()) ?: 'el respaldo quedó vacío';
            File::deleteDirectory($carpeta); // un respaldo incompleto no debe parecer bueno
            $this->error('No se pudo respaldar la base de datos: '.Str::limit($error, 300));
            Bitacora::anotar(EventoBitacora::Creado, 'Respaldo FALLIDO de la base de datos', null, ['error' => ['despues' => Str::limit($error, 250)]]);

            return self::FAILURE;
        }

        if (! $this->option('sin-archivos')) {
            $archivos = Process::timeout(3600)->path(storage_path('app'))
                ->run('tar -czf '.escapeshellarg($carpeta.'/archivos.tar.gz').' --ignore-failed-read private public');
            if (! $archivos->successful()) {
                $this->warn('La base quedó respaldada, pero no los archivos: '.Str::limit(trim($archivos->errorOutput()), 300));
            }
        }

        $borrados = $this->limpiar($raiz, (int) config('respaldos.dias'));
        $tamano = collect(File::files($carpeta))->sum(fn ($f) => $f->getSize());
        $this->info("Respaldo listo en {$carpeta} (".number_format($tamano / 1048576, 1).' MB). Respaldos antiguos borrados: '.$borrados.'.');
        Bitacora::anotar(EventoBitacora::Creado, 'Respaldo diario', null, ['carpeta' => ['despues' => basename($carpeta)], 'mb' => ['despues' => round($tamano / 1048576, 1)]]);

        return self::SUCCESS;
    }

    /** Borra carpetas de respaldo con más de $dias días. Nunca toca la más reciente. */
    private function limpiar(string $raiz, int $dias): int
    {
        $carpetas = collect(File::directories($raiz))->filter(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', basename($d)))->sort()->values();
        $limite = now()->subDays(max(1, $dias))->format('Y-m-d_His');
        $borrar = $carpetas->slice(0, -1)->filter(fn ($d) => basename($d) < $limite);
        $borrar->each(fn ($d) => File::deleteDirectory($d));

        return $borrar->count();
    }
}
