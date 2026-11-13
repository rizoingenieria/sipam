<?php

namespace App\Filament\Resources\Cargos;

use App\Filament\Resources\Cargos\Pages\ManageCargos;
use App\Filament\Resources\Recurso;
use App\Models\Cargo;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Nombres de los cargos tal como deben aparecer en los documentos (p. ej., «Síndica Única»). */
class CargoResource extends Recurso
{
    protected static ?string $model = Cargo::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Documentos oficiales';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $modelLabel = 'cargo';

    protected static ?string $pluralModelLabel = 'cargos';

    protected static ?int $navigationSort = 21;

    public static function canCreate(): bool
    {
        return false; // los cargos los define el reglamento; aquí sólo se ajusta su nombre
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')->label('Nombre del cargo')
                ->helperText('Tal como debe aparecer en los documentos, por ejemplo «Síndica Única».')
                ->required()->maxLength(150),
            Toggle::make('firma_documentos')->label('Firma documentos del sistema'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('orden')
            ->paginated(false)
            ->columns([
                TextColumn::make('nombre')->label('Cargo')->weight('semibold')->wrap(),
                IconColumn::make('firma_documentos')->label('Firma documentos')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('Editar')->modalHeading('Editar cargo')->modalSubmitActionLabel('Guardar cargo')]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCargos::route('/')];
    }
}
