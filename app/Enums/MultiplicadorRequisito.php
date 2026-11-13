<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Cuándo un requisito se pide más de una vez según el caso, en lugar de una cantidad fija.
 */
enum MultiplicadorRequisito: string implements HasLabel
{
    /** Uno por cada titular o copropietario del título (anuencias, art. 28). */
    case Titulares = 'titulares';

    /** Uno por cada persona inhumada en el espacio (actas de defunción, art. 27, fr. V, inciso e). */
    case Inhumados = 'inhumados';

    public function getLabel(): string
    {
        return match ($this) {
            self::Titulares => 'Uno por cada titular o copropietario',
            self::Inhumados => 'Uno por cada persona sepultada en el espacio',
        };
    }
}
