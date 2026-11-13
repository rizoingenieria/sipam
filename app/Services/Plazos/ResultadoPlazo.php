<?php

namespace App\Services\Plazos;

use App\Models\ReglaPlazo;
use App\Support\Motivo;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Resultado de evaluar si una exhumación cumple su plazo.
 * Se guarda junto con la resolución: la regla determinante y su fundamento explican el porqué.
 */
final readonly class ResultadoPlazo
{
    /**
     * @param  Collection<int, ReglaPlazo>  $reglasAplicadas
     */
    public function __construct(
        public bool $permitido,
        public ?CarbonInterface $fechaPermitida,
        public ?ReglaPlazo $reglaDeterminante,
        public Collection $reglasAplicadas,
        public string $explicacion,
        public ?Motivo $motivo = null,
    ) {}

    /** Datos para guardar en la resolución del trámite. */
    public function paraResolucion(): array
    {
        return [
            'permitido' => $this->permitido,
            'fecha_permitida' => $this->fechaPermitida?->toDateString(),
            'regla_plazo_id' => $this->reglaDeterminante?->id,
            'reglas_aplicadas' => $this->reglasAplicadas->pluck('id')->all(),
            'explicacion' => $this->explicacion,
        ];
    }
}
