<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/** Los cinco trámites del MVP. */
enum TipoTramite: string implements HasIcon, HasLabel
{
    case Adquisicion = 'adquisicion';
    case Regularizacion = 'regularizacion';
    case Inhumacion = 'inhumacion';
    case Exhumacion = 'exhumacion';
    case Construccion = 'construccion';

    public function getLabel(): string
    {
        return match ($this) {
            self::Adquisicion => 'Adquisición de título',
            self::Regularizacion => 'Regularización o cambio de propietario',
            self::Inhumacion => 'Inhumación',
            self::Exhumacion => 'Exhumación',
            self::Construccion => 'Permiso de construcción o remodelación',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Adquisicion => Heroicon::OutlinedDocumentCheck,
            self::Regularizacion => Heroicon::OutlinedArrowsRightLeft,
            self::Inhumacion => Heroicon::OutlinedArrowDownTray,
            self::Exhumacion => Heroicon::OutlinedArrowUpTray,
            self::Construccion => Heroicon::OutlinedBuildingLibrary,
        };
    }

    public function fundamento(): string
    {
        return match ($this) {
            self::Adquisicion => 'Arts. 33 a 35',
            self::Regularizacion => 'Arts. 27, 28 y 67',
            self::Inhumacion => 'Arts. 36, 37 y 46',
            self::Exhumacion => 'Arts. 38 a 44 y 68',
            self::Construccion => 'Arts. 51 a 59',
        };
    }
}
