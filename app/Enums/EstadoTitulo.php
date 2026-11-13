<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum EstadoTitulo: string implements HasColor, HasIcon, HasLabel
{
    case Vigente = 'vigente';
    case Vencido = 'vencido';
    case Sustituido = 'sustituido'; // por cesión, herencia o regularización
    case Cancelado = 'cancelado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vigente => 'Vigente',
            self::Vencido => 'Vencido',
            self::Sustituido => 'Sustituido',
            self::Cancelado => 'Cancelado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Vigente => 'success',
            self::Vencido => 'warning',
            self::Sustituido => 'gray',
            self::Cancelado => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Vigente => Heroicon::OutlinedDocumentCheck,
            self::Vencido => Heroicon::OutlinedClock,
            self::Sustituido => Heroicon::OutlinedArrowPath,
            self::Cancelado => Heroicon::OutlinedXCircle,
        };
    }
}
