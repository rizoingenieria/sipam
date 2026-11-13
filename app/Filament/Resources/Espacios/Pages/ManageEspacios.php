<?php

namespace App\Filament\Resources\Espacios\Pages;

use App\Filament\Resources\Espacios\EspacioResource;
use App\Filament\Support\Avisos;
use App\Models\Espacio;
use App\Models\Lote;
use App\Models\Panteon;
use App\Support\AccionBloqueada;
use App\Support\Motivo;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;

class ManageEspacios extends ManageRecords
{
    protected static string $resource = EspacioResource::class;

    public function getSubheading(): ?string
    {
        return 'Busca un espacio por su lote o su clave. Para dar de alta muchos a la vez, abre el panteón y usa «Generar lotes».';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Agregar un espacio')
                ->modalHeading('Agregar un espacio')
                ->modalSubmitActionLabel('Guardar espacio')
                ->createAnother(false)
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('panteon_id')->label('Panteón')
                            ->options(fn () => Panteon::orderBy('nombre')->pluck('nombre', 'id'))
                            ->default(fn () => Panteon::count() === 1 ? Panteon::value('id') : null)
                            ->required()->live()->afterStateUpdated(fn (Set $set) => $set('seccion_id', null)),
                        Select::make('seccion_id')->label('Sección')
                            ->options(fn (Get $get) => $get('panteon_id') ? EspacioResource::opcionesSeccion((int) $get('panteon_id')) : [])
                            ->disabled(fn (Get $get) => blank($get('panteon_id')))
                            ->helperText(fn (Get $get) => blank($get('panteon_id')) ? 'Primero elige el panteón.' : null)
                            ->required(),
                        TextInput::make('lote_numero')->label('Número de lote')->required()->maxLength(20)
                            ->helperText('Si el lote no existe, se crea.'),
                        TextInput::make('numero')->label('Número del espacio en el lote')->required()->maxLength(20)->default('1'),
                    ]),
                    ...EspacioResource::camposDelEspacio(),
                ])
                ->using(function (array $data, Action $action): Espacio {
                    return Avisos::intentar(fn () => DB::transaction(function () use ($data): Espacio {
                        $lote = Lote::firstOrCreate(['seccion_id' => $data['seccion_id'], 'numero' => trim($data['lote_numero'])]);
                        if ($lote->espacios()->where('numero', trim($data['numero']))->exists()) {
                            throw AccionBloqueada::por(Motivo::de(
                                "El lote {$lote->numero} ya tiene el espacio {$data['numero']}.",
                                'Búscalo en la lista para editarlo, o usa otro número.',
                            ));
                        }
                        unset($data['panteon_id'], $data['seccion_id'], $data['lote_numero']);

                        return $lote->espacios()->create([...$data, 'numero' => trim($data['numero'])]);
                    }), $action);
                }),
        ];
    }
}
