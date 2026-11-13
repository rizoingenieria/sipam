<?php

namespace App\Filament\Resources\Conceptos;

use App\Enums\CategoriaConcepto;
use App\Filament\Resources\Conceptos\Pages\ManageConceptos;
use App\Filament\Resources\Recurso;
use App\Models\ConceptoCobro;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Qué se cobra (título, servicios, obras). El importe de cada uno está en el tabulador.
 * La clave interna no se edita: los trámites la guardan.
 */
class ConceptoResource extends Recurso
{
    protected static ?string $model = ConceptoCobro::class;

    protected static ?string $slug = 'conceptos-de-cobro';

    protected static string|\UnitEnum|null $navigationGroup = 'Cobros';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $modelLabel = 'concepto de cobro';

    protected static ?string $pluralModelLabel = 'conceptos de cobro';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')->label('Nombre')->helperText('Como aparecerá en la orden de pago.')->required()->maxLength(150),
            Grid::make(2)->schema([
                Select::make('categoria')->label('Tipo')->options(CategoriaConcepto::class)->required()
                    ->disabledOn('edit'),
                TextInput::make('unidad')->label('Se cobra por')->default('servicio')->required()->maxLength(30)
                    ->datalist(['servicio', 'pieza', 'm²', 'metro lineal'])->helperText('Por ejemplo «servicio» o «m²».'),
            ]),
            Toggle::make('por_seccion')->label('El importe cambia según la sección del panteón')
                ->helperText('No se puede cambiar después: las cuotas ya capturadas dependen de esto.')
                ->disabledOn('edit'),
            TextInput::make('fundamento')->label('Fundamento')->helperText('Opcional.')->maxLength(255),
            Toggle::make('activo')->label('Se cobra actualmente')->default(true)
                ->helperText('Apágalo si ya no se cobra. No se borra, porque hay órdenes de pago que lo usaron.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('orden')
            ->paginated(false)
            ->columns([
                TextColumn::make('nombre')->label('Concepto')->weight('semibold')->wrap()->description(fn (ConceptoCobro $c) => $c->fundamento),
                TextColumn::make('categoria')->label('Tipo')->badge()->color('gray'),
                TextColumn::make('unidad')->label('Se cobra por')
                    ->description(fn (ConceptoCobro $c) => $c->por_seccion ? 'Según la sección' : null),
                TextColumn::make('activo')->label('Estado')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Se cobra' : 'Ya no se cobra')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('categoria')->label('Tipo')->options(CategoriaConcepto::class),
                TernaryFilter::make('activo')->label('Estado')->placeholder('Todos')->trueLabel('Se cobran')->falseLabel('Ya no se cobran')->default(true),
            ])
            ->recordActions([
                EditAction::make()->label('Editar')->modalHeading('Editar concepto')->modalSubmitActionLabel('Guardar concepto'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageConceptos::route('/')];
    }
}
