<?php

namespace App\Console\Commands;

use App\Services\Instalacion\Verificacion;
use Illuminate\Console\Command;

/** Revisión del servidor antes de abrir el sistema y cada semana después. */
class VerificarInstalacion extends Command
{
    protected $signature = 'panteones:verificar-instalacion';

    protected $description = 'Revisa que el servidor esté listo para producción: seguridad, base de datos, cron y respaldos';

    public function handle(Verificacion $verificacion): int
    {
        $puntos = $verificacion->revisar();
        $marca = ['ok' => '<fg=green>✔ Bien</>', 'aviso' => '<fg=yellow>! Revisar</>', 'falla' => '<fg=red>✘ Corregir</>'];
        $this->table(['Punto', 'Estado', 'Detalle'], array_map(fn ($p) => [$p['punto'], $marca[$p['estado']], $p['detalle']], $puntos));

        $fallas = collect($puntos)->where('estado', 'falla')->count();
        $avisos = collect($puntos)->where('estado', 'aviso')->count();
        if ($fallas > 0) {
            $this->error("Hay {$fallas} puntos por corregir antes de operar.");

            return self::FAILURE;
        }
        $this->info($avisos > 0 ? "Listo para operar. Hay {$avisos} puntos que conviene revisar." : 'Todo en orden.');

        return self::SUCCESS;
    }
}
