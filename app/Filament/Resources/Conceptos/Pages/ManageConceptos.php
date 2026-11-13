<?php

namespace App\Filament\Resources\Conceptos\Pages;

use App\Filament\Resources\Conceptos\ConceptoResource;
use App\Models\ConceptoCobro;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Str;

class ManageConceptos extends ManageRecords
{
    protected static string $resource = ConceptoResource::class;

    public function getSubheading(): ?string
    {
        return 'Qué se cobra. El importe de cada concepto se captura en el tabulador.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo concepto')
                ->modalHeading('Nuevo concepto de cobro')
                ->modalSubmitActionLabel('Guardar concepto')
                ->createAnother(false)
                ->mutateDataUsing(function (array $data): array {
                    $base = Str::limit(Str::slug($data['nombre'], '_'), 44, '');
                    $clave = $base;
                    for ($i = 2; ConceptoCobro::where('clave', $clave)->exists(); $i++) {
                        $clave = "{$base}_{$i}";
                    }

                    return $data + ['clave' => $clave, 'orden' => (int) ConceptoCobro::max('orden') + 10];
                }),
        ];
    }
}
