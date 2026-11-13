<?php

namespace App\Filament\Resources\Panteones;

use App\Enums\EstadoPanteon;
use App\Filament\Resources\Panteones\Pages\EditPanteon;
use App\Filament\Resources\Panteones\Pages\ListPanteones;
use App\Filament\Resources\Panteones\RelationManagers\SeccionesRelationManager;
use App\Filament\Resources\Recurso;
use App\Models\Panteon;
use App\Support\Formato;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PanteonResource extends Recurso
{
    protected static ?string $model = Panteon::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $modelLabel = 'panteón';

    protected static ?string $pluralModelLabel = 'panteones';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'panteones';

    public static function getEloquentQuery(): Builder
    {
        $espacios = DB::table('espacios')->join('lotes', 'lotes.id', '=', 'espacios.lote_id')
            ->join('secciones', 'secciones.id', '=', 'lotes.seccion_id')
            ->whereColumn('secciones.panteon_id', 'panteones.id')->selectRaw('count(*)');

        return parent::getEloquentQuery()->withCount('secciones')->selectSub($espacios, 'espacios_count');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del panteón')
                ->columns(2)
                ->schema([
                    TextInput::make('nombre')->label('Nombre')->required()->maxLength(150)->unique(ignoreRecord: true)->columnSpanFull(),
                    TextInput::make('clave')->label('Clave corta')
                        ->helperText('Letras o números que identifican al panteón en folios y listas, por ejemplo «MUN».')
                        ->required()->maxLength(20)->unique(ignoreRecord: true)->extraInputAttributes(['style' => 'text-transform:uppercase'])
                        ->dehydrateStateUsing(fn (?string $state) => mb_strtoupper(trim((string) $state))),
                    Select::make('estado')->label('Estado')->options(EstadoPanteon::class)->default(EstadoPanteon::Operando)->required()
                        ->helperText('Saturado: sólo se inhuma en fosas con título y restos de más de seis años. Clausurado: ya no recibe inhumaciones (art. 105).'),
                    TextInput::make('domicilio')->label('Domicilio')->maxLength(255)->columnSpanFull(),
                    TextInput::make('extension_m2')->label('Extensión')->numeric()->minValue(0)->suffix('m²')
                        ->helperText('Opcional. La pide el informe anual a la Secretaría de Salud (art. 21, fr. XXX).'),
                    TextInput::make('capacidad')->label('Capacidad')->numeric()->integer()->minValue(0)->suffix('espacios')
                        ->helperText('Opcional. También para el informe a Salud.'),
                    Textarea::make('observaciones')->label('Observaciones')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nombre')
            ->columns([
                TextColumn::make('nombre')->label('Panteón')->weight('semibold')->searchable()->description(fn (Panteon $p) => $p->domicilio)->wrap(),
                TextColumn::make('estado')->label('Estado')->badge(),
                TextColumn::make('secciones_count')->label('Secciones')->alignEnd(),
                TextColumn::make('espacios_count')->label('Espacios')->alignEnd()->formatStateUsing(fn ($state) => Formato::numero($state)),
            ])
            ->emptyStateHeading('Aún no hay panteones registrados')
            ->emptyStateDescription('Registra cada panteón del municipio; después agrega sus secciones, lotes y espacios.')
            ->recordActions([EditAction::make()->label('Abrir')]);
    }

    public static function getRelations(): array
    {
        return [SeccionesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPanteones::route('/'),
            'edit' => EditPanteon::route('/{record}'),
        ];
    }
}
