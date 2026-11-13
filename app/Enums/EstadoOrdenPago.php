<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EstadoOrdenPago: string implements HasColor, HasLabel
{
    case Emitida = 'emitida';
    case Pagada = 'pagada';
    case Cancelada = 'cancelada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Emitida => 'Emitida',
            self::Pagada => 'Pagada',
            self::Cancelada => 'Cancelada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Emitida => 'warning',
            self::Pagada => 'success',
            self::Cancelada => 'gray',
        };
    }
}
