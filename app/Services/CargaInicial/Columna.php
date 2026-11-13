<?php

namespace App\Services\CargaInicial;

/** Una columna de la plantilla: cómo se llama, si es obligatoria, qué poner y un ejemplo. */
final readonly class Columna
{
    public function __construct(
        public string $clave,
        public string $encabezado,
        public bool $obligatoria,
        public string $ayuda,
        public string $ejemplo,
    ) {}

    public static function de(string $clave, string $encabezado, bool $obligatoria, string $ayuda, string $ejemplo): self
    {
        return new self($clave, $encabezado, $obligatoria, $ayuda, $ejemplo);
    }
}
