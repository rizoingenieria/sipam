<?php

namespace App\Filament\Resources\Regimenes;

use App\Filament\Resources\Recurso;
use App\Filament\Resources\Regimenes\Pages\ManageRegimenes;
use App\Filament\Support\AccionesVersion;
use App\Models\Regimen;
use App\Support\Formato;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tipos de título (perpetuidad, temporalidad). No se crean desde aquí: el sistema los usa por su
 * clave. Lo que una reforma puede cambiar —duración, renovación, cesión— se cambia con fecha.
 */
class RegimenResource extends Recurso
{
    protected static ?string $model = Regimen::class;

    protected static ?string $slug = 'tipos-de-titulo';

    protected static string|\UnitEnum|null $navigationGroup = 'Reglamento';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'tipo de título';

    protected static ?string $pluralModelLabel = 'tipos de título';

    protected static ?int $navigationSort = 40;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ultimas()->orderBy('nombre');
    }

    public static function duracion(Regimen $r): string
    {
        return $r->duracion_anios >= 99 ? 'Sin vencimiento' : Formato::cantidad($r->duracion_anios, 'año')
            .($r->duracion_maxima_anios ? ', hasta '.Formato::cantidad($r->duracion_maxima_anios, 'año').' con renovaciones' : '');
    }

    public static function condiciones(Regimen $r): string
    {
        return ($r->renovable ? 'Se puede renovar' : 'No se renueva').' · '.($r->permite_cesion ? 'Se puede ceder' : 'No se puede ceder');
    }

    public static function table(Table $table): Table
    {
        $valor = fn (Regimen $r): string => self::duracion($r).'. '.self::condiciones($r).'.';

        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('nombre')->label('Tipo de título')->weight('semibold')->description(fn (Regimen $r) => $r->fundamento)->wrap(),
                TextColumn::make('duracion_anios')->label('Duración')->state(fn (Regimen $r) => self::duracion($r))->wrap()
                    ->description(fn (Regimen $r) => AccionesVersion::descripcionProgramada($r, $valor)),
                TextColumn::make('renovable')->label('Condiciones')->state(fn (Regimen $r) => self::condiciones($r))->wrap(),
            ])
            ->recordActions(AccionesVersion::fila(
                AccionesVersion::cambiar('Cambiar condiciones', [
                    Grid::make(2)->schema([
                        TextInput::make('duracion_anios')->label('Duración')->numeric()->integer()->minValue(1)->maxValue(99)->suffix('años')->required()
                            ->helperText('99 equivale a «sin vencimiento».'),
                        TextInput::make('duracion_maxima_anios')->label('Máximo con renovaciones')->numeric()->integer()->minValue(1)->maxValue(99)->suffix('años')
                            ->helperText('Vacío si no hay máximo.'),
                        Toggle::make('renovable')->label('Se puede renovar'),
                        Toggle::make('permite_cesion')->label('Se puede ceder a otra persona')->helperText('Art. 27.'),
                    ]),
                ], ['duracion_anios', 'duracion_maxima_anios', 'renovable', 'permite_cesion']),
                [
                    AccionesVersion::corregirTexto([
                        TextInput::make('nombre')->label('Nombre')->required()->maxLength(100),
                        TextInput::make('fundamento')->label('Fundamento')->required()->maxLength(255),
                    ]),
                    AccionesVersion::deshacer(),
                    AccionesVersion::historial($valor),
                ],
            ));
    }

    public static function getPages(): array
    {
        return ['index' => ManageRegimenes::route('/')];
    }
}
