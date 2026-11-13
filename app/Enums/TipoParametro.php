<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TipoParametro: string implements HasLabel
{
    case Entero = 'entero';
    case Decimal = 'decimal';
    case Serie = 'serie';   // prefijo de folio: letras mayúsculas y números
    case Texto = 'texto';

    public function getLabel(): string
    {
        return match ($this) {
            self::Entero => 'Número entero',
            self::Decimal => 'Número con decimales',
            self::Serie => 'Serie de folio',
            self::Texto => 'Texto',
        };
    }
}
