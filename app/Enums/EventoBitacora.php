<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EventoBitacora: string implements HasColor, HasLabel
{
    case Creado = 'creado';
    case Modificado = 'modificado';
    case Eliminado = 'eliminado';
    case Entrada = 'entrada';
    case Salida = 'salida';
    case EntradaFallida = 'entrada_fallida';

    public function getLabel(): string
    {
        return match ($this) {
            self::Creado => 'Alta',
            self::Modificado => 'Cambio',
            self::Eliminado => 'Baja',
            self::Entrada => 'Entró al sistema',
            self::Salida => 'Salió del sistema',
            self::EntradaFallida => 'Intento de entrada fallido',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Creado => 'success',
            self::Modificado => 'info',
            self::Eliminado, self::EntradaFallida => 'danger',
            self::Entrada, self::Salida => 'gray',
        };
    }
}
