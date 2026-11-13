<?php

namespace App\Services\Diagnostico;

use App\Support\Motivo;

/**
 * Algo que falta configurar.
 * - Bloqueante: el sistema no puede operar hasta resolverlo.
 * - Advertencia: el sistema opera, pero un trámite o documento concreto fallará.
 */
final readonly class Pendiente
{
    public const BLOQUEANTE = 'bloqueante';

    public const ADVERTENCIA = 'advertencia';

    public function __construct(
        public string $nivel,
        public string $area,
        public Motivo $motivo,
    ) {}

    public static function bloqueante(string $area, string $problema, string $queHacer): self
    {
        return new self(self::BLOQUEANTE, $area, Motivo::de($problema, $queHacer));
    }

    public static function advertencia(string $area, string $problema, string $queHacer): self
    {
        return new self(self::ADVERTENCIA, $area, Motivo::de($problema, $queHacer));
    }

    public function esBloqueante(): bool
    {
        return $this->nivel === self::BLOQUEANTE;
    }
}
