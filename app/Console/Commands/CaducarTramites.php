<?php

namespace App\Console\Commands;

use App\Services\Tramites\GestorTramites;
use Illuminate\Console\Command;

/**
 * Caduca las adquisiciones con más de 12 meses sin concluir (art. 34).
 * Programar una vez al día en routes/console.php:
 *     Schedule::command('panteones:caducar-tramites')->dailyAt('01:15');
 */
class CaducarTramites extends Command
{
    protected $signature = 'panteones:caducar-tramites';

    protected $description = 'Caduca las solicitudes de adquisición con más de 12 meses sin concluir (art. 34)';

    public function handle(GestorTramites $gestor): int
    {
        $n = $gestor->caducarVencidos();
        $this->info($n === 0 ? 'No hay trámites por caducar.' : "Trámites caducados: {$n}.");

        return self::SUCCESS;
    }
}
