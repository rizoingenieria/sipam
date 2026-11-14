<?php

namespace App\Console\Commands;

use App\Enums\EventoBitacora;
use App\Models\ArchivoAcervo;
use App\Models\Bitacora;
use App\Support\Auditoria;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Comprueba que los archivos del acervo siguen intactos: que existan y que su huella SHA-256 sea la misma
 * que al subirlos. Por partes: cada noche revisa los que no se han verificado en N días, así todo el acervo
 * se revisa una vez al mes sin cargar el servidor. Lo que falle queda en la bitácora y en la ficha del documento.
 */
class VerificarAcervo extends Command
{
    protected $signature = 'panteones:verificar-acervo {--dias=30 : Revisar los que no se han verificado en estos días} {--todos : Revisar todos ahora}';

    protected $description = 'Comprueba que los archivos del acervo histórico existan y no hayan cambiado';

    public function handle(): int
    {
        $limite = Carbon::now()->subDays((int) $this->option('dias'));
        $consulta = ArchivoAcervo::with('documento')
            ->when(! $this->option('todos'), fn ($q) => $q->where(fn ($w) => $w->whereNull('verificado_en')->orWhere('verificado_en', '<', $limite)));

        $revisados = 0;
        $fallas = [];
        $consulta->chunkById(200, function ($archivos) use (&$revisados, &$fallas): void {
            foreach ($archivos as $a) {
                $disco = Storage::disk($a->disco);
                $integro = $disco->exists($a->ruta) && hash_file('sha256', $disco->path($a->ruta)) === $a->sha256;
                // La verificación no es un cambio de datos: no se anota archivo por archivo.
                Auditoria::sinAnotar(fn () => $a->forceFill(['verificado_en' => Carbon::now(), 'integro' => $integro])->save());
                $revisados++;
                if (! $integro) {
                    $fallas[] = $a->documento->folio.' archivo '.$a->orden.' ('.$a->nombre_original.')'.($disco->exists($a->ruta) ? ': cambió' : ': no está');
                }
            }
        });

        if ($fallas !== []) {
            Bitacora::anotar(EventoBitacora::Modificado, 'Acervo: '.count($fallas).' archivos NO coinciden con su original', null,
                ['archivos' => ['despues' => implode('; ', array_slice($fallas, 0, 20))]]);
            $this->error(count($fallas).' archivos no coinciden con su original. Recupéralos del respaldo del acervo:');
            foreach ($fallas as $f) {
                $this->line(' - '.$f);
            }

            return self::FAILURE;
        }

        $this->info("Acervo verificado: {$revisados} archivos revisados, todos íntegros.");

        return self::SUCCESS;
    }
}
