<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Inhumado: el cuerpo sigue sepultado en su espacio.
 * Restos en sitio: exhumado por temporalidad y depositado al pie de la misma fosa (art. 44).
 * Trasladado: los restos salieron del municipio (art. 21, fr. III, inciso c).
 */
enum EstadoInhumado: string implements HasColor, HasIcon, HasLabel
{
    case Inhumado = 'inhumado';
    case RestosEnSitio = 'restos_en_sitio';
    case Trasladado = 'trasladado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inhumado => 'Inhumado',
            self::RestosEnSitio => 'Restos áridos en sitio',
            self::Trasladado => 'Trasladado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Inhumado => 'primary',
            self::RestosEnSitio, self::Trasladado => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Inhumado => Heroicon::OutlinedArrowDownTray,
            self::RestosEnSitio => Heroicon::OutlinedArchiveBox,
            self::Trasladado => Heroicon::OutlinedTruck,
        };
    }
}
