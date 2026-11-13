<?php

namespace App\Filament\Resources\Parentescos;

use App\Enums\LineaParentesco;
use App\Filament\Resources\Parentescos\Pages\ManageParentescos;
use App\Filament\Resources\Recurso;
use App\Models\Parentesco;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Parentescos con el finado y el orden en que la familia puede autorizar una inhumación o exhumación. */
class ParentescoResource extends Recurso
{
    protected static ?string $model = Parentesco::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Reglamento';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'parentesco';

    protected static ?string $pluralModelLabel = 'parentescos';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')->label('Parentesco')->helperText('Por ejemplo «Hijo o hija».')->required()->maxLength(100),
            Grid::make(2)->schema([
                Select::make('linea')->label('Línea')->options(LineaParentesco::class)->required()->live(),
                TextInput::make('grado')->label('Grado')->numeric()->integer()->minValue(1)->maxValue(6)
                    ->helperText('Vacío para el cónyuge.'),
                TextInput::make('prelacion')->label('Turno para autorizar')->numeric()->integer()->minValue(1)->maxValue(99)->required()
                    ->helperText('1 es el primero. Si falta quien tiene el 1, autoriza quien sigue.'),
                Toggle::make('puede_autorizar')->label('Puede autorizar')->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('prelacion')
            ->paginated(false)
            ->columns([
                TextColumn::make('prelacion')->label('Turno')->alignCenter()->width('6rem'),
                TextColumn::make('nombre')->label('Parentesco')->weight('semibold')
                    ->description(fn (Parentesco $p) => $p->grado ? $p->linea->getLabel().", {$p->grado}.º grado" : null),
                TextColumn::make('puede_autorizar')->label('Autoriza')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Puede autorizar' : 'No autoriza')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make()->label('Editar')->modalHeading('Editar parentesco')->modalSubmitActionLabel('Guardar parentesco'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageParentescos::route('/')];
    }
}
