<?php

namespace App\Filament\Resources\Regimenes\Pages;

use App\Filament\Resources\Regimenes\RegimenResource;
use Filament\Resources\Pages\ManageRecords;

class ManageRegimenes extends ManageRecords
{
    protected static string $resource = RegimenResource::class;

    public function getSubheading(): ?string
    {
        return 'Cuánto dura cada tipo de título y qué permite. Un cambio aplica a los títulos que se expidan desde la fecha que elijas; los ya expedidos conservan sus condiciones.';
    }
}
