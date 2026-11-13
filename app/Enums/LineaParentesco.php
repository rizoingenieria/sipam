<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum LineaParentesco: string implements HasLabel
{
    case Recta = 'recta';
    case Colateral = 'colateral';
    case Conyugal = 'conyugal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Recta => 'Línea recta',
            self::Colateral => 'Línea colateral',
            self::Conyugal => 'Cónyuge',
        };
    }
}
