<?php

namespace App\Services\Tramites;

use App\Models\Consecutivo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Folios oficiales SERIE-AÑO-NNNNNN: consecutivos, sin repetir y sin huecos.
 *
 * El número se toma con bloqueo de fila DENTRO de la transacción del trámite: si el trámite
 * no se guarda, el número se libera y lo usa el siguiente. Así no quedan huecos.
 *
 * La fila de cada serie se crea ANTES y APARTE, en una conexión propia que confirma al instante.
 * Bloquear una fila que todavía no existe en MySQL toma un candado de hueco, y dos capturas que
 * crean la misma serie a la vez se bloquean entre sí (deadlock 1213). Pasa el primer día hábil
 * de cada año y al cambiar una serie. Crear la fila no consume ningún número, así que hacerlo
 * fuera de la transacción no deja huecos.
 */
final class Folios
{
    public function siguiente(string $serie, ?int $anio = null): string
    {
        $anio ??= (int) Carbon::now()->year;
        $this->asegurarSerie($serie, $anio);

        return DB::transaction(function () use ($serie, $anio): string {
            $consecutivo = Consecutivo::where('serie', $serie)->where('anio', $anio)->lockForUpdate()->firstOrFail();
            $consecutivo->increment('ultimo');

            return sprintf('%s-%d-%06d', $serie, $anio, $consecutivo->ultimo);
        });
    }

    /**
     * La revisión de existencia también va por la conexión aparte: dentro de una transacción, la conexión
     * principal lee una foto del inicio y no ve una serie creada después. Sin esto, pedir dos folios de una
     * serie nueva en la misma operación (dos órdenes de pago) intentaba crearla otra vez y esperaba un
     * candado que la misma operación tenía: 50 segundos y error.
     */
    private function asegurarSerie(string $serie, int $anio): void
    {
        if (Consecutivo::where('serie', $serie)->where('anio', $anio)->exists()) {
            return; // el caso de todos los días: la serie ya existe y se ve
        }

        $principal = config('database.default');
        $aislada = "{$principal}_folios";
        if (config("database.connections.{$aislada}") === null) {
            config(["database.connections.{$aislada}" => config("database.connections.{$principal}")]);
        }
        $conexion = DB::connection($aislada);

        if ($conexion->table('consecutivos')->where('serie', $serie)->where('anio', $anio)->exists()) {
            return;
        }

        $ahora = Carbon::now();
        $conexion->table('consecutivos')->insertOrIgnore([
            'serie' => $serie, 'anio' => $anio, 'ultimo' => 0, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
    }
}
