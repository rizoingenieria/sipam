<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** De quién es cada documento que exige un trámite. */
enum ParteTramite: string implements HasLabel
{
    case Solicitante = 'solicitante';
    case Titular = 'titular';
    case NuevoTitular = 'nuevo_titular';
    case Testigo = 'testigo';
    case Autorizante = 'autorizante';
    case Constructor = 'constructor';
    case Inhumado = 'inhumado';
    case UltimoInhumado = 'ultimo_inhumado';
    case InhumadosDelLote = 'inhumados_del_lote';
    case Ayuntamiento = 'ayuntamiento';

    public function getLabel(): string
    {
        return match ($this) {
            self::Solicitante => 'Solicitante',
            self::Titular => 'Titular o copropietario',
            self::NuevoTitular => 'Nuevo titular',
            self::Testigo => 'Testigo',
            self::Autorizante => 'Quien autoriza',
            self::Constructor => 'Responsable de la obra',
            self::Inhumado => 'Finado',
            self::UltimoInhumado => 'Último sepultado',
            self::InhumadosDelLote => 'Cada inhumado del lote',
            self::Ayuntamiento => 'Ayuntamiento',
        };
    }
}
