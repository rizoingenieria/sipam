<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Fundamento: art. 3, fr. XI (cripta), XIX (fosa), XXIX (nicho); art. 32 (nichos sólo para restos áridos o cremados).
 */
enum TipoEspacio: string implements HasColor, HasIcon, HasLabel
{
    case Fosa = 'fosa';
    case Nicho = 'nicho';
    case Cripta = 'cripta';
    case Gaveta = 'gaveta';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fosa => 'Fosa',
            self::Nicho => 'Nicho',
            self::Cripta => 'Cripta',
            self::Gaveta => 'Gaveta',
        };
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Fosa => Heroicon::OutlinedMapPin,
            self::Nicho => Heroicon::OutlinedSquare2Stack,
            self::Cripta => Heroicon::OutlinedBuildingLibrary,
            self::Gaveta => Heroicon::OutlinedArchiveBox,
        };
    }

    /** Un nicho no recibe cadáveres, sólo restos áridos o cremados (art. 32). */
    public function admiteCadaver(): bool
    {
        return $this !== self::Nicho;
    }
}
