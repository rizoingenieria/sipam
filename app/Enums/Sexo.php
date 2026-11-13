<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Dato del libro de inhumaciones (art. 21, fr. III, inciso a). «No especificado» cubre el legado. */
enum Sexo: string implements HasLabel
{
    case Mujer = 'mujer';
    case Hombre = 'hombre';
    case NoEspecificado = 'no_especificado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mujer => 'Mujer',
            self::Hombre => 'Hombre',
            self::NoEspecificado => 'No especificado',
        };
    }
}
