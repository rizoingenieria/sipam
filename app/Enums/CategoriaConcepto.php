<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CategoriaConcepto: string implements HasLabel
{
    case Titulo = 'titulo';
    case Servicio = 'servicio';
    case Obra = 'obra';
    case Documento = 'documento';

    public function getLabel(): string
    {
        return match ($this) {
            self::Titulo => 'Título de derechos de uso',
            self::Servicio => 'Servicio',
            self::Obra => 'Construcción u obra',
            self::Documento => 'Documento',
        };
    }
}
