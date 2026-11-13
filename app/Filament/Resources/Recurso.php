<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;
use Illuminate\Support\Str;

/**
 * Base de los recursos del panel. En español sólo va mayúscula la primera letra
 * («Tabulador de cuotas», no «Tabulador De Cuotas»), y Filament capitaliza cada palabra.
 */
abstract class Recurso extends Resource
{
    public static function getTitleCaseModelLabel(): string
    {
        return Str::ucfirst(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
