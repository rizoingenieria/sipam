<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/** Los cuatro libros cronológicos del art. 21, fr. III. */
enum TipoMovimiento: string implements HasColor, HasIcon, HasLabel
{
    case Inhumacion = 'inhumacion';
    case Exhumacion = 'exhumacion';
    case Reinhumacion = 'reinhumacion';
    case Traslado = 'traslado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inhumacion => 'Inhumación',
            self::Exhumacion => 'Exhumación',
            self::Reinhumacion => 'Reinhumación',
            self::Traslado => 'Traslado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Inhumacion, self::Reinhumacion => 'primary',
            self::Exhumacion => 'warning',
            self::Traslado => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Inhumacion => Heroicon::OutlinedArrowDownTray,
            self::Exhumacion => Heroicon::OutlinedArrowUpTray,
            self::Reinhumacion => Heroicon::OutlinedArrowUturnLeft,
            self::Traslado => Heroicon::OutlinedTruck,
        };
    }

    /** Qué columnas exige cada libro; el modelo lo valida al crear. */
    public function exigeEspacioOrigen(): bool
    {
        return in_array($this, [self::Exhumacion, self::Traslado], true);
    }

    public function exigeEspacioDestino(): bool
    {
        return in_array($this, [self::Inhumacion, self::Reinhumacion], true);
    }
}
