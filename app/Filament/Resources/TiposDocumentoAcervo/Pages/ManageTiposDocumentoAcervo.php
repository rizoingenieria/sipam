<?php

namespace App\Filament\Resources\TiposDocumentoAcervo\Pages;

use App\Filament\Resources\TiposDocumentoAcervo\TipoDocumentoAcervoResource;
use App\Models\TipoDocumentoAcervo;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Str;

class ManageTiposDocumentoAcervo extends ManageRecords
{
    protected static string $resource = TipoDocumentoAcervoResource::class;

    public function getSubheading(): ?string
    {
        return 'Cómo se clasifican los documentos del acervo histórico (libros, títulos antiguos, actas, planos…).';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo tipo')
                ->modalHeading('Nuevo tipo de documento')
                ->modalSubmitActionLabel('Guardar')
                ->createAnother(false)
                ->mutateDataUsing(function (array $data): array {
                    $base = Str::limit(Str::slug($data['nombre'], '_'), 30, '') ?: 'tipo';
                    $clave = $base;
                    for ($i = 2; TipoDocumentoAcervo::where('clave', $clave)->exists(); $i++) {
                        $clave = "{$base}_{$i}";
                    }

                    return $data + ['clave' => $clave];
                }),
        ];
    }
}
