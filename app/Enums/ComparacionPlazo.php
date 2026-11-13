<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * «Al menos N años» se cumple el día del aniversario; «más de N años» (art. 105), al día siguiente.
 */
enum ComparacionPlazo: string implements HasLabel
{
    case AlMenos = 'al_menos';
    case MasDe = 'mas_de';

    public function getLabel(): string
    {
        return match ($this) {
            self::AlMenos => 'Al menos',
            self::MasDe => 'Más de',
        };
    }
}
