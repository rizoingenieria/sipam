<?php

namespace App\Filament\Resources\TiposDocumentoAcervo;

use App\Filament\Resources\Recurso;
use App\Filament\Resources\TiposDocumentoAcervo\Pages\ManageTiposDocumentoAcervo;
use App\Models\TipoDocumentoAcervo;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Tipos de documento del acervo histórico. No se borran: un tipo que ya no se usa se apaga. */
class TipoDocumentoAcervoResource extends Recurso
{
    protected static ?string $model = TipoDocumentoAcervo::class;

    protected static ?string $slug = 'tipos-de-documento-del-acervo';

    protected static string|\UnitEnum|null $navigationGroup = 'Reglamento';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'tipo de documento';

    protected static ?string $pluralModelLabel = 'tipos de documento del acervo';

    protected static ?string $navigationLabel = 'Tipos de documento del acervo';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')->label('Nombre')->required()->maxLength(100)->helperText('Por ejemplo «Libro o foja de registro».'),
            TextInput::make('descripcion')->label('Para qué documentos')->maxLength(255),
            TextInput::make('orden')->label('Lugar en la lista')->numeric()->integer()->minValue(1)->maxValue(999)->default(100)
                ->helperText('Los números menores aparecen primero.'),
            Toggle::make('activo')->label('Se puede elegir')->default(true)
                ->helperText('Apágalo si ya no se usa. Los documentos que ya lo tienen lo conservan.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('orden')
            ->paginated(false)
            ->columns([
                TextColumn::make('nombre')->label('Tipo')->weight('semibold')->description(fn (TipoDocumentoAcervo $t) => $t->descripcion),
                TextColumn::make('documentos_count')->label('Documentos')->counts('documentos')->alignEnd(),
                TextColumn::make('activo')->label('Estado')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Se puede elegir' : 'Apagado')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make()->label('Editar')->modalHeading('Editar tipo de documento')->modalSubmitActionLabel('Guardar'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTiposDocumentoAcervo::route('/')];
    }
}
