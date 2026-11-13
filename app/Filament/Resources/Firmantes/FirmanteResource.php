<?php

namespace App\Filament\Resources\Firmantes;

use App\Filament\Resources\Firmantes\Pages\ManageFirmantes;
use App\Filament\Resources\Recurso;
use App\Filament\Support\AccionesVersion;
use App\Models\Cargo;
use App\Models\Firmante;
use App\Support\Formato;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién firma cada documento. Un documento toma al firmante vigente en su fecha de emisión,
 * así que registrar un relevo no altera los documentos ya emitidos.
 */
class FirmanteResource extends Recurso
{
    protected static ?string $model = Firmante::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Documentos oficiales';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPencil;

    protected static ?string $modelLabel = 'firmante';

    protected static ?string $pluralModelLabel = 'firmantes';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->abiertas()->with('cargo')
            ->join('cargos', 'cargos.id', '=', 'firmantes.cargo_id')->select('firmantes.*')->orderBy('cargos.orden');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('cargo_id')
                ->label('Cargo')
                ->options(fn () => Cargo::where('firma_documentos', true)->orderBy('orden')->get()
                    ->filter(fn (Cargo $c) => $c->firmantes()->abiertas()->doesntExist())
                    ->pluck('nombre', 'id'))
                ->helperText('Sólo aparecen los cargos que aún no tienen firmante. Para cambiar a quien ya firma, usa «Registrar relevo».')
                ->required(),
            TextInput::make('titulo_profesional')->label('Título profesional')->helperText('Opcional. Por ejemplo «Lic.» o «Mtra.».')->maxLength(20),
            TextInput::make('nombre')->label('Nombre completo')->required()->maxLength(200),
            DatePicker::make('vigente_desde')->label('Ocupa el cargo desde')->default(now())->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $valor = fn (Firmante $f): string => $f->nombre_para_firma;

        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('cargo.nombre')->label('Cargo')->weight('semibold')->wrap(),
                TextColumn::make('nombre')->label('Firma')->formatStateUsing(fn ($state, Firmante $f) => $f->nombre_para_firma)
                    ->description(fn (Firmante $f) => AccionesVersion::descripcionProgramada($f, $valor))->wrap(),
                TextColumn::make('vigente_desde')->label('Desde')->formatStateUsing(fn ($state) => Formato::fecha($state)),
            ])
            ->emptyStateHeading('Aún no hay firmantes registrados')
            ->emptyStateDescription('Los documentos oficiales llevan el nombre de quien ocupa cada cargo. Registra a cada persona.')
            ->recordActions(AccionesVersion::fila(
                AccionesVersion::cambiar('Registrar relevo', [
                    TextInput::make('titulo_profesional')->label('Título profesional')->maxLength(20),
                    TextInput::make('nombre')->label('Nombre de quien ocupa ahora el cargo')->required()->maxLength(200),
                ], []),
                [
                    AccionesVersion::corregirTexto([
                        TextInput::make('titulo_profesional')->label('Título profesional')->maxLength(20),
                        TextInput::make('nombre')->label('Nombre completo')->required()->maxLength(200)
                            ->helperText('Sólo para corregir cómo está escrito. Si cambió la persona, usa «Registrar relevo».'),
                    ]),
                    AccionesVersion::deshacer(),
                    AccionesVersion::historial($valor),
                ],
            ));
    }

    public static function getPages(): array
    {
        return ['index' => ManageFirmantes::route('/')];
    }
}
