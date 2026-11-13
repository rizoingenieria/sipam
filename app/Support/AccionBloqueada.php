<?php

namespace App\Support;

use DomainException;

/**
 * Algo no puede hacerse, por uno o varios motivos en lenguaje llano.
 * La interfaz atrapa esta excepción en un solo lugar y muestra cada motivo con su «qué hacer».
 * Lleva TODOS los motivos, no sólo el primero, para resolver todo de una vez.
 */
class AccionBloqueada extends DomainException
{
    /** @var list<Motivo> */
    public readonly array $motivos;

    /** @param list<Motivo> $motivos */
    public function __construct(array $motivos)
    {
        $this->motivos = array_values($motivos);
        parent::__construct(implode(' ', array_map('strval', $this->motivos)));
    }

    public static function por(Motivo|string ...$motivos): static
    {
        return new static(array_map(fn ($m) => $m instanceof Motivo ? $m : Motivo::de($m), array_values($motivos)));
    }

    /** @return list<string> Los motivos como texto corrido. */
    public function textos(): array
    {
        return array_map('strval', $this->motivos);
    }
}
