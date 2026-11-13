<?php

namespace App\Services\Tramites;

use App\Enums\Permiso;
use App\Support\Motivo;

/**
 * Lo que sigue en un trámite, dicho para quien lo atiende: qué hay que hacer, qué falta,
 * con qué botón se hace y qué permiso lo permite.
 */
final readonly class PasoTramite
{
    /**
     * @param  list<Motivo>  $pendientes  Lo que falta antes de poder dar el paso.
     * @param  ?string  $accion  Nombre de la acción del expediente que da el paso; null si no hay.
     */
    public function __construct(
        public string $titulo,
        public string $explicacion,
        public array $pendientes = [],
        public ?string $accion = null,
        public ?string $etiquetaAccion = null,
        public ?Permiso $permiso = null,
        public bool $terminado = false,
    ) {}

    public function listo(): bool
    {
        return $this->pendientes === [];
    }
}
