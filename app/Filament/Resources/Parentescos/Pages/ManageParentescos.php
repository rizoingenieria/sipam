<?php

namespace App\Filament\Resources\Parentescos\Pages;

use App\Filament\Resources\Parentescos\ParentescoResource;
use App\Models\Parentesco;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Str;

class ManageParentescos extends ManageRecords
{
    protected static string $resource = ParentescoResource::class;

    public function getSubheading(): ?string
    {
        return 'Quién de la familia puede autorizar una inhumación o exhumación, y en qué orden.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo parentesco')
                ->modalHeading('Nuevo parentesco')
                ->modalSubmitActionLabel('Guardar parentesco')
                ->createAnother(false)
                ->mutateDataUsing(function (array $data): array {
                    $base = Str::limit(Str::slug($data['nombre'], '_'), 24, '');
                    $clave = $base;
                    for ($i = 2; Parentesco::where('clave', $clave)->exists(); $i++) {
                        $clave = "{$base}_{$i}";
                    }

                    return $data + ['clave' => $clave];
                }),
        ];
    }
}
