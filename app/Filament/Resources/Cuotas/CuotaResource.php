<?php

namespace App\Filament\Resources\Cuotas;

use App\Enums\CategoriaConcepto;
use App\Filament\Resources\Cuotas\Pages\ManageCuotas;
use App\Filament\Resources\Recurso;
use App\Filament\Support\AccionesVersion;
use App\Models\ConceptoCobro;
use App\Models\Cuota;
use App\Models\Seccion;
use App\Support\Formato;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabulador: el importe de cada concepto (y sección) que aprueba el Cabildo (arts. 65, 69 y 70).
 * Cada orden de pago guarda la cuota con la que se emitió; cambiar el importe no altera las ya emitidas.
 */
class CuotaResource extends Recurso
{
    protected static ?string $model = Cuota::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Cobros';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'cuota';

    protected static ?string $pluralModelLabel = 'tabulador de cuotas';

    protected static ?string $navigationLabel = 'Tabulador';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->abiertas()->with(['concepto', 'seccion.panteon'])
            ->join('conceptos_cobro', 'conceptos_cobro.id', '=', 'cuotas.concepto_id')
            ->select('cuotas.*')
            ->orderBy('conceptos_cobro.orden');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('concepto_id')
                ->label('Concepto')
                ->options(fn () => ConceptoCobro::where('activo', true)->orderBy('orden')->pluck('nombre', 'id'))
                ->required()
                ->live()
                ->afterStateUpdated(fn ($set) => $set('seccion_id', null)),
            Select::make('seccion_id')
                ->label('Sección')
                ->helperText('Este concepto cuesta distinto en cada sección del panteón.')
                ->options(fn () => Seccion::with('panteon')->get()->mapWithKeys(fn (Seccion $s) => [$s->id => "{$s->panteon->nombre}, sección {$s->clave}"]))
                ->visible(fn (Get $get) => (bool) ConceptoCobro::find($get('concepto_id'))?->por_seccion)
                ->required(fn (Get $get) => (bool) ConceptoCobro::find($get('concepto_id'))?->por_seccion),
            TextInput::make('importe')->label('Importe')->prefix('$')->numeric()->minValue(0)->required(),
            TextInput::make('acuerdo_cabildo')
                ->label('Acuerdo de Cabildo')
                ->helperText('Acta y punto que aprueban esta cuota, por ejemplo «Acta 3/2027, punto 5».')
                ->required()->maxLength(255),
            TextInput::make('fundamento')->label('Fundamento')->helperText('Opcional.')->maxLength(255),
            DatePicker::make('vigente_desde')->label('Aplica a partir del')->default(now())->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $valor = fn (Cuota $c): string => Formato::dinero($c->importe).' ('.$c->acuerdo_cabildo.')';

        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('concepto.nombre')->label('Concepto')->weight('semibold')->wrap()
                    ->description(fn (Cuota $c) => $c->seccion ? "{$c->seccion->panteon->nombre}, sección {$c->seccion->clave}" : null),
                TextColumn::make('importe')->label('Importe')->formatStateUsing(fn ($state) => Formato::dinero($state))
                    ->alignEnd()->description(fn (Cuota $c) => AccionesVersion::descripcionProgramada($c, $valor)),
                TextColumn::make('acuerdo_cabildo')->label('Acuerdo de Cabildo')->wrap(),
                TextColumn::make('vigente_desde')->label('Desde')->formatStateUsing(fn ($state) => Formato::fecha($state)),
            ])
            ->filters([
                SelectFilter::make('categoria')
                    ->label('Tipo de concepto')
                    ->options(CategoriaConcepto::class)
                    ->query(fn (Builder $query, array $data) => filled($data['value']) ? $query->where('conceptos_cobro.categoria', $data['value']) : $query),
            ])
            ->emptyStateHeading('Aún no hay cuotas capturadas')
            ->emptyStateDescription('Sin cuotas no se pueden emitir órdenes de pago. Captura las que aprobó el Cabildo este año.')
            ->recordActions(AccionesVersion::fila(
                AccionesVersion::cambiar('Cambiar importe', [
                    TextInput::make('importe')->label('Importe nuevo')->prefix('$')->numeric()->minValue(0)->required(),
                    TextInput::make('acuerdo_cabildo')->label('Acuerdo de Cabildo que lo aprueba')->required()->maxLength(255),
                ], ['importe', 'acuerdo_cabildo']),
                [
                    AccionesVersion::corregirTexto([
                        TextInput::make('acuerdo_cabildo')->label('Acuerdo de Cabildo')->required()->maxLength(255),
                        TextInput::make('fundamento')->label('Fundamento')->maxLength(255),
                    ]),
                    AccionesVersion::deshacer(),
                    AccionesVersion::historial($valor),
                ],
            ));
    }

    public static function getPages(): array
    {
        return ['index' => ManageCuotas::route('/')];
    }
}
