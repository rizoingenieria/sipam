<?php

namespace App\Services\Inventario;

use App\Enums\EventoBitacora;
use App\Enums\TipoEspacio;
use App\Models\Bitacora;
use App\Models\Espacio;
use App\Models\Lote;
use App\Models\Seccion;
use App\Support\AccionBloqueada;
use App\Support\Auditoria;
use App\Support\Motivo;
use Illuminate\Support\Facades\DB;

/**
 * Alta masiva de lotes y espacios de una sección: capturar 400 lotes uno por uno no es realista.
 * Lo que ya existe se deja igual; se puede correr de nuevo sin duplicar.
 */
final class GeneradorInventario
{
    public const MAXIMO_LOTES = 2000;

    /** @return array{lotes_nuevos: int, lotes_existentes: int, espacios_nuevos: int} */
    public function generar(Seccion $seccion, int $desde, int $hasta, int $espaciosPorLote, TipoEspacio $tipo): array
    {
        $motivos = [];
        if ($desde < 1 || $hasta < $desde) {
            $motivos[] = Motivo::de('El rango de lotes no es válido.', 'El primer lote debe ser 1 o más y no mayor que el último.');
        }
        if ($hasta - $desde + 1 > self::MAXIMO_LOTES) {
            $motivos[] = Motivo::de('Son demasiados lotes para una sola vez.', 'Genera como máximo '.self::MAXIMO_LOTES.' lotes por vez.');
        }
        if ($espaciosPorLote < 1 || $espaciosPorLote > 50) {
            $motivos[] = Motivo::de('El número de espacios por lote no es válido.', 'Usa un número entre 1 y 50.');
        }
        if ($motivos !== []) {
            throw new AccionBloqueada($motivos);
        }

        // Un renglón de resumen en la bitácora, no uno por cada lote y espacio.
        $resultado = Auditoria::sinAnotar(fn () => $this->crear($seccion, $desde, $hasta, $espaciosPorLote, $tipo));
        Bitacora::anotar(EventoBitacora::Creado, "Inventario: sección {$seccion->clave} de {$seccion->panteon->nombre}", $seccion, [
            'lotes' => ['despues' => "del {$desde} al {$hasta}"],
            'lotes_nuevos' => ['despues' => $resultado['lotes_nuevos']],
            'espacios_nuevos' => ['despues' => $resultado['espacios_nuevos']],
        ]);

        return $resultado;
    }

    /** @return array{lotes_nuevos: int, lotes_existentes: int, espacios_nuevos: int} */
    private function crear(Seccion $seccion, int $desde, int $hasta, int $espaciosPorLote, TipoEspacio $tipo): array
    {
        return DB::transaction(function () use ($seccion, $desde, $hasta, $espaciosPorLote, $tipo): array {
            $existentes = Lote::where('seccion_id', $seccion->id)->pluck('id', 'numero');
            $resultado = ['lotes_nuevos' => 0, 'lotes_existentes' => 0, 'espacios_nuevos' => 0];

            for ($n = $desde; $n <= $hasta; $n++) {
                $numero = (string) $n;
                if ($existentes->has($numero)) {
                    $resultado['lotes_existentes']++;

                    continue;
                }
                $lote = Lote::create(['seccion_id' => $seccion->id, 'numero' => $numero]);
                $resultado['lotes_nuevos']++;

                for ($e = 1; $e <= $espaciosPorLote; $e++) {
                    Espacio::create(['lote_id' => $lote->id, 'tipo' => $tipo, 'numero' => (string) $e]);
                    $resultado['espacios_nuevos']++;
                }
            }

            return $resultado;
        });
    }
}
