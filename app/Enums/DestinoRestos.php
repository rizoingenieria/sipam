<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Qué pasa con los restos tras la exhumación. */
enum DestinoRestos: string implements HasLabel
{
    case EnSitio = 'en_sitio';           // al pie de la misma fosa (art. 44)
    case OtroEspacio = 'otro_espacio';   // reinhumación en el municipio
    case Traslado = 'traslado';          // fuera del municipio o del estado (art. 21, fr. III, inciso c)

    public function getLabel(): string
    {
        return match ($this) {
            self::EnSitio => 'Al pie de la misma fosa',
            self::OtroEspacio => 'Reinhumación en otro espacio del municipio',
            self::Traslado => 'Traslado fuera del municipio',
        };
    }
}
