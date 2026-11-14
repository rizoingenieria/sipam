<?php

namespace App\Console\Commands;

use App\Enums\EventoBitacora;
use App\Models\ArchivoAcervo;
use App\Models\Bitacora;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Respaldo del acervo histórico, aparte del respaldo diario: los originales escaneados pesan mucho y no cambian,
 * así que basta copiar cada noche los nuevos. La copia nunca se borra ni se sobrescribe: si un archivo ya está
 * y su huella coincide, se deja; si no coincide, se avisa (no se pisa con algo que podría estar dañado).
 * Conviene que RESPALDOS_ACERVO_RUTA esté en otro disco o se sincronice a otro equipo.
 */
class RespaldarAcervo extends Command
{
    protected $signature = 'panteones:respaldar-acervo';

    protected $description = 'Copia al respaldo los archivos nuevos del acervo histórico (nunca borra ni sobrescribe)';

    public function handle(): int
    {
        $destino = rtrim((string) config('respaldos.acervo_ruta'), '/');
        File::ensureDirectoryExists($destino);

        $copiados = 0;
        $yaEstaban = 0;
        $problemas = [];
        ArchivoAcervo::orderBy('id')->chunkById(200, function ($archivos) use ($destino, &$copiados, &$yaEstaban, &$problemas): void {
            foreach ($archivos as $a) {
                $origen = Storage::disk($a->disco)->path($a->ruta);
                $copia = $destino.'/'.$a->ruta;

                if (is_file($copia)) {
                    if (filesize($copia) === $a->bytes && hash_file('sha256', $copia) === $a->sha256) {
                        $yaEstaban++;
                    } else {
                        $problemas[] = "{$a->ruta}: la copia del respaldo no coincide (no se sobrescribió)";
                    }

                    continue;
                }
                if (! is_file($origen) || hash_file('sha256', $origen) !== $a->sha256) {
                    $problemas[] = "{$a->ruta}: el original falta o cambió; no se copió";

                    continue;
                }

                File::ensureDirectoryExists(dirname($copia));
                $temporal = $copia.'.copiando';
                if (! @copy($origen, $temporal) || hash_file('sha256', $temporal) !== $a->sha256) {
                    @unlink($temporal);
                    $problemas[] = "{$a->ruta}: no se pudo copiar (¿falta espacio en el disco del respaldo?)";

                    continue;
                }
                rename($temporal, $copia);
                $copiados++;
            }
        });

        Cache::forever('panteones:respaldo-acervo', now()->toIso8601String());
        if ($problemas !== []) {
            Bitacora::anotar(EventoBitacora::Creado, 'Respaldo del acervo con PROBLEMAS', null, [
                'copiados' => ['despues' => $copiados], 'problemas' => ['despues' => Str::limit(implode('; ', $problemas), 250)],
            ]);
            $this->error(count($problemas).' archivos con problemas:');
            foreach ($problemas as $p) {
                $this->line(' - '.$p);
            }

            return self::FAILURE;
        }
        if ($copiados > 0) {
            Bitacora::anotar(EventoBitacora::Creado, 'Respaldo del acervo', null, ['copiados' => ['despues' => $copiados]]);
        }
        $this->info("Respaldo del acervo en {$destino}: {$copiados} archivos nuevos copiados, {$yaEstaban} ya estaban.");

        return self::SUCCESS;
    }
}
