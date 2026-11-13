<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cómo nació cada título. «Legado» marca los cargados desde el sistema o libros anteriores.
 * Fundamento: arts. 26 y 33 (adquisición), 27 (cesión), 67 (herencia o legado).
 */
enum OrigenTitulo: string implements HasColor, HasLabel
{
    case Adquisicion = 'adquisicion';
    case Cesion = 'cesion';
    case Herencia = 'herencia';
    case Regularizacion = 'regularizacion';
    case Legado = 'legado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Adquisicion => 'Adquisición',
            self::Cesion => 'Cesión o donación',
            self::Herencia => 'Herencia o legado',
            self::Regularizacion => 'Regularización',
            self::Legado => 'Carga del sistema anterior',
        };
    }

    public function getColor(): string
    {
        return $this === self::Legado ? 'gray' : 'primary';
    }
}
