<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EstadoRequisitoEntregado: string implements HasColor, HasLabel
{
    case Pendiente = 'pendiente';
    case Entregado = 'entregado';
    case NoAplica = 'no_aplica';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Entregado => 'Entregado',
            self::NoAplica => 'No aplica',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendiente => 'warning',
            self::Entregado => 'success',
            self::NoAplica => 'gray',
        };
    }
}
