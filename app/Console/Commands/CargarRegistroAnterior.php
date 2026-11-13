<?php

namespace App\Console\Commands;

use App\Services\CargaInicial\CargaInicial;
use App\Services\CargaInicial\LectorHoja;
use App\Services\CargaInicial\Plantillas;
use App\Services\CargaInicial\TipoCarga;
use App\Support\AccionBloqueada;
use Illuminate\Console\Command;

/** Lo mismo que la pantalla «Carga inicial», para archivos muy grandes o para soporte técnico. */
class CargarRegistroAnterior extends Command
{
    protected $signature = 'panteones:cargar
        {tipo : espacios, titulos o sepultados}
        {archivo? : Ruta del Excel (.xlsx) o CSV}
        {--plantilla= : En lugar de cargar, guarda la plantilla vacía en esta ruta}
        {--confirmar : Carga los registros; sin esta opción sólo se revisa}';

    protected $description = 'Revisa o carga el registro anterior (espacios, títulos, sepultados) desde una plantilla de Excel';

    public function handle(LectorHoja $lector, CargaInicial $carga, Plantillas $plantillas): int
    {
        $tipo = TipoCarga::tryFrom($this->argument('tipo'));
        if (! $tipo) {
            $this->error('El tipo debe ser: espacios, titulos o sepultados.');

            return self::FAILURE;
        }
        if ($ruta = $this->option('plantilla')) {
            $plantillas->crear($tipo, $ruta);
            $this->info("Plantilla guardada en {$ruta}");

            return self::SUCCESS;
        }
        $archivo = $this->argument('archivo');
        if (! $archivo || ! is_file($archivo)) {
            $this->error('Indica la ruta de un archivo que exista.');

            return self::FAILURE;
        }

        try {
            $filas = $lector->leer($archivo, $tipo);
        } catch (AccionBloqueada $e) {
            $this->error($e->motivos[0]->problema.' '.$e->motivos[0]->queHacer);

            return self::FAILURE;
        }

        $r = $this->option('confirmar') ? $carga->cargar($tipo, $filas, basename($archivo)) : $carga->revisar($tipo, $filas);
        $filasTabla = [];
        foreach ($r->errores as $n => $ms) {
            foreach ($ms as $m) {
                $filasTabla[] = [$n, 'Error', $m];
            }
        }
        foreach ($r->avisos as $n => $ms) {
            foreach ($ms as $m) {
                $filasTabla[] = [$n, 'Aviso', $m];
            }
        }
        if ($filasTabla) {
            usort($filasTabla, fn ($a, $b) => $a[0] <=> $b[0]);
            $this->table(['Renglón', 'Tipo', 'Detalle'], $filasTabla);
        }
        $this->line("Renglones: {$r->renglones} · nuevos: {$r->nuevos} · ya existían: {$r->existentes} · con errores: {$r->totalErrores()}");

        if (! $r->sinErrores()) {
            $this->error('No se cargó nada. Corrige los errores y vuelve a intentarlo.');

            return self::FAILURE;
        }
        $this->info($this->option('confirmar') ? 'Carga terminada.' : 'Revisión sin errores. Para cargar, repite con --confirmar.');

        return self::SUCCESS;
    }
}
