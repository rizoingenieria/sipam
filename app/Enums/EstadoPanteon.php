<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Saturado: sólo se inhuma en fosas con título y restos de más de seis años (art. 105).
 * Clausurado: no se permiten más inhumaciones (art. 105).
 */
enum EstadoPanteon: string implements HasColor, HasIcon, HasLabel
{
    case Operando = 'operando';
    case Saturado = 'saturado';
    case Clausurado = 'clausurado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Operando => 'Operando',
            self::Saturado => 'Saturado',
            self::Clausurado => 'Clausurado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Operando => 'success',
            self::Saturado => 'warning',
            self::Clausurado => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Operando => Heroicon::OutlinedCheckCircle,
            self::Saturado => Heroicon::OutlinedExclamationTriangle,
            self::Clausurado => Heroicon::OutlinedLockClosed,
        };
    }

    public function admiteInhumaciones(): bool
    {
        return $this !== self::Clausurado;
    }
}
