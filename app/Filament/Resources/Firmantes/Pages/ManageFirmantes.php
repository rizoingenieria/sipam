<?php

namespace App\Filament\Resources\Firmantes\Pages;

use App\Filament\Resources\Firmantes\FirmanteResource;
use App\Models\Cargo;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFirmantes extends ManageRecords
{
    protected static string $resource = FirmanteResource::class;

    public function getSubheading(): ?string
    {
        $vacantes = Cargo::where('firma_documentos', true)->orderBy('orden')->get()
            ->filter(fn (Cargo $c) => $c->firmanteEn() === null)->pluck('nombre');

        return $vacantes->isEmpty()
            ? 'Quién ocupa cada cargo. Al registrar un relevo, los documentos ya emitidos conservan el nombre anterior.'
            : 'Falta registrar quién firma como: '.$vacantes->implode(', ').'.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Registrar firmante')
                ->modalHeading('Registrar firmante')
                ->modalSubmitActionLabel('Guardar firmante')
                ->createAnother(false),
        ];
    }
}
