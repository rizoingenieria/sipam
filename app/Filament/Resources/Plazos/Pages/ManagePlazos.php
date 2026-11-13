<?php

namespace App\Filament\Resources\Plazos\Pages;

use App\Filament\Resources\Plazos\PlazoResource;
use App\Models\ReglaPlazo;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Str;

class ManagePlazos extends ManageRecords
{
    protected static string $resource = PlazoResource::class;

    public function getSubheading(): ?string
    {
        return 'Cuántos años deben pasar para exhumar. Si a un finado le aplican varias reglas, manda la más tardía. Si una reforma cambia un plazo, usa «Cambiar regla» con la fecha en que entra en vigor.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva regla')
                ->modalHeading('Nueva regla de plazo')
                ->modalSubmitActionLabel('Guardar regla')
                ->createAnother(false)
                ->mutateDataUsing(fn (array $data): array => $data + ['clave' => self::claveLibre($data['descripcion'])]),
        ];
    }

    /** Clave interna a partir del nombre; nadie tiene que inventarla. */
    public static function claveLibre(string $nombre): string
    {
        $base = Str::limit(Str::slug($nombre), 44, '');
        $clave = $base;
        for ($i = 2; ReglaPlazo::where('clave', $clave)->exists(); $i++) {
            $clave = "{$base}-{$i}";
        }

        return $clave;
    }
}
