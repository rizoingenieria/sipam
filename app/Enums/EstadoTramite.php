<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Ciclo de vida común de los cinco trámites (documento de contexto y alcance).
 * Las transiciones permitidas viven en App\Models\Tramite::TRANSICIONES.
 */
enum EstadoTramite: string implements HasColor, HasIcon, HasLabel
{
    case Capturado = 'capturado';
    case EnRevision = 'en_revision';
    case EnPublicacion = 'en_publicacion';
    case PorPagar = 'por_pagar';
    case PorAutorizar = 'por_autorizar';
    case Concluido = 'concluido';
    case Rechazado = 'rechazado';
    case Caducado = 'caducado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Capturado => 'Capturado',
            self::EnRevision => 'En revisión',
            self::EnPublicacion => 'En publicación',
            self::PorPagar => 'Por pagar',
            self::PorAutorizar => 'Por autorizar',
            self::Concluido => 'Concluido',
            self::Rechazado => 'Rechazado',
            self::Caducado => 'Caducado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Capturado, self::EnRevision, self::EnPublicacion => 'primary',
            self::PorPagar, self::PorAutorizar => 'warning',
            self::Concluido => 'success',
            self::Rechazado => 'danger',
            self::Caducado => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Capturado => Heroicon::OutlinedDocumentCheck,
            self::EnRevision => Heroicon::OutlinedQuestionMarkCircle,
            self::EnPublicacion => Heroicon::OutlinedMapPin,
            self::PorPagar => Heroicon::OutlinedClock,
            self::PorAutorizar => Heroicon::OutlinedLockClosed,
            self::Concluido => Heroicon::OutlinedCheckCircle,
            self::Rechazado => Heroicon::OutlinedXCircle,
            self::Caducado => Heroicon::OutlinedArchiveBox,
        };
    }

    public function esTerminal(): bool
    {
        return in_array($this, [self::Concluido, self::Rechazado, self::Caducado], true);
    }
}
