<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/** Fundamento: art. 25, fr. VII (beneficiarios); art. 28 (copropiedad). */
enum RolTitularidad: string implements HasColor, HasIcon, HasLabel
{
    case Titular = 'titular';
    case Copropietario = 'copropietario';
    case Beneficiario = 'beneficiario';

    public function getLabel(): string
    {
        return match ($this) {
            self::Titular => 'Titular',
            self::Copropietario => 'Copropietario',
            self::Beneficiario => 'Beneficiario',
        };
    }

    public function getColor(): string
    {
        return $this === self::Beneficiario ? 'gray' : 'primary';
    }

    public function getIcon(): Heroicon
    {
        return $this === self::Beneficiario ? Heroicon::OutlinedHeart : Heroicon::OutlinedUser;
    }

    /** Titulares y copropietarios ejercen el derecho y firman anuencias (art. 28). */
    public function ejerceDerecho(): bool
    {
        return $this !== self::Beneficiario;
    }
}
