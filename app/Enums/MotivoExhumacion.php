<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Fundamento: art. 41 (casos en que procede la exhumación) y art. 105 (reutilización con panteón saturado).
 */
enum MotivoExhumacion: string implements HasLabel
{
    case Vencimiento = 'vencimiento';             // art. 41, fr. II
    case Traslado = 'traslado';                   // art. 41, fr. III
    case ReutilizacionFosa = 'reutilizacion_fosa'; // inhumar de nuevo en la misma fosa (art. 105)
    case OrdenAutoridad = 'orden_autoridad';      // art. 41, fr. I; arts. 40, 42 y 68

    public function getLabel(): string
    {
        return match ($this) {
            self::Vencimiento => 'Vencimiento de la temporalidad',
            self::Traslado => 'Traslado a otro lugar solicitado por familiares',
            self::ReutilizacionFosa => 'Reutilización de la fosa',
            self::OrdenAutoridad => 'Orden de autoridad judicial o fiscalía',
        };
    }

    /** La orden de autoridad no se sujeta a plazo (art. 40) ni causa derechos (art. 68). */
    public function sujetoAPlazo(): bool
    {
        return $this !== self::OrdenAutoridad;
    }
}
