<?php

namespace App\Filament\Resources\Requisitos\Pages;

use App\Enums\TipoTramite;
use App\Filament\Resources\Requisitos\RequisitoResource;
use App\Models\Requisito;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Str;

class ManageRequisitos extends ManageRecords
{
    protected static string $resource = RequisitoResource::class;

    public function getSubheading(): ?string
    {
        return 'Lo que ventanilla pide en cada trámite. Si el reglamento cambia, agrega, cambia o deja de pedir un documento con la fecha en que aplica; los trámites ya iniciados no cambian.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo requisito')
                ->modalHeading('Nuevo requisito')
                ->modalSubmitActionLabel('Guardar requisito')
                ->createAnother(false)
                ->mutateDataUsing(function (array $data): array {
                    $tipo = $data['tipo_tramite'] instanceof TipoTramite ? $data['tipo_tramite']->value : $data['tipo_tramite'];
                    $base = Str::limit(Str::slug($data['documento'], '_'), 54, '');
                    $clave = $base;
                    for ($i = 2; Requisito::where('tipo_tramite', $tipo)->where('clave', $clave)->exists(); $i++) {
                        $clave = "{$base}_{$i}";
                    }

                    // Al final de la lista de su trámite; nadie tiene que calcular el orden.
                    return $data + [
                        'clave' => $clave,
                        'orden' => (int) Requisito::where('tipo_tramite', $tipo)->max('orden') + 10,
                    ];
                }),
        ];
    }
}
