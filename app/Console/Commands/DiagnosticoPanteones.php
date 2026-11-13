<?php

namespace App\Console\Commands;

use App\Services\Diagnostico\DiagnosticoConfiguracion;
use App\Services\Diagnostico\Pendiente;
use Illuminate\Console\Command;

/**
 * Lista lo que falta configurar. Devuelve código 1 si hay bloqueantes: sirve en el despliegue.
 */
class DiagnosticoPanteones extends Command
{
    protected $signature = 'panteones:diagnostico';

    protected $description = 'Revisa lo que falta configurar para operar el sistema';

    public function handle(DiagnosticoConfiguracion $diagnostico): int
    {
        $pendientes = $diagnostico->revisar();

        if ($pendientes->isEmpty()) {
            $this->info('Todo listo: no falta nada por configurar.');

            return self::SUCCESS;
        }

        foreach ($pendientes as $p) {
            /** @var Pendiente $p */
            $marca = $p->esBloqueante() ? '<fg=red>✗ Bloquea</>' : '<fg=yellow>! Revisar</>';
            $this->line("{$marca}  <options=bold>{$p->area}</>: {$p->motivo->problema}");
            $this->line("           {$p->motivo->queHacer}");
        }

        $bloqueantes = $pendientes->filter->esBloqueante()->count();
        $this->newLine();
        $this->line($bloqueantes > 0
            ? "<fg=red>{$bloqueantes} pendiente(s) impiden operar.</>"
            : '<fg=yellow>El sistema puede operar; los pendientes afectan trámites o documentos concretos.</>');

        return $bloqueantes > 0 ? self::FAILURE : self::SUCCESS;
    }
}
