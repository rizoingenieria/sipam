<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TipoHorario: string implements HasLabel
{
    case Servicio = 'servicio';
    case Recepcion = 'recepcion';

    public function getLabel(): string
    {
        return match ($this) {
            self::Servicio => 'Servicio al público',
            self::Recepcion => 'Recepción de documentos',
        };
    }
}
