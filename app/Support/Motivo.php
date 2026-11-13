<?php

namespace App\Support;

use Stringable;

/**
 * Un motivo por el que algo no puede hacerse, escrito para quien atiende la ventanilla:
 *
 * - problema:   qué pasa, en una frase y sin tecnicismos.
 * - queHacer:   el paso concreto que lo resuelve.
 * - fundamento: el artículo que lo exige, para quien lo pida; la interfaz lo muestra en letra secundaria.
 *
 * Sigue la guía «Papel y tinta», sección 10: de tú, qué pasa y qué hacer, sin culpar ni exclamar.
 */
final readonly class Motivo implements Stringable
{
    public function __construct(
        public string $problema,
        public ?string $queHacer = null,
        public ?string $fundamento = null,
    ) {}

    public static function de(string $problema, ?string $queHacer = null, ?string $fundamento = null): self
    {
        return new self($problema, $queHacer, $fundamento);
    }

    /** Texto corrido, para bitácoras, comandos y correos. */
    public function __toString(): string
    {
        return trim(implode(' ', array_filter([
            $this->problema,
            $this->queHacer,
            $this->fundamento !== null ? "({$this->fundamento})" : null,
        ])));
    }

    public function toArray(): array
    {
        return ['problema' => $this->problema, 'que_hacer' => $this->queHacer, 'fundamento' => $this->fundamento];
    }
}
