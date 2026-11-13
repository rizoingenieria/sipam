<?php

namespace App\Filament\Resources\Plazos;

use App\Enums\ComparacionPlazo;
use App\Enums\MotivoExhumacion;
use App\Filament\Resources\Plazos\Pages\ManagePlazos;
use App\Filament\Resources\Recurso;
use App\Filament\Support\AccionesVersion;
use App\Models\Regimen;
use App\Models\ReglaPlazo;
use App\Support\Formato;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Plazos para exhumar. Cada regla dice cuántos años deben pasar desde la inhumación y a quién aplica.
 * Si a un finado le aplican varias, manda la que da la fecha más tardía (EvaluadorPlazoExhumacion).
 * Una reforma se captura aquí, con la fecha en que entra en vigor; no requiere programar.
 */
class PlazoResource extends Recurso
{
    protected static ?string $model = ReglaPlazo::class;

    protected static ?string $slug = 'plazos-para-exhumar';

    protected static string|\UnitEnum|null $navigationGroup = 'Reglamento';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'regla de plazo';

    protected static ?string $pluralModelLabel = 'plazos para exhumar';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ultimas()->orderBy('anios_minimos');
    }

    /** Lo que decide la regla: se usa al crearla y al cambiarla. */
    public static function camposDeLaRegla(): array
    {
        $regimenes = fn () => Regimen::abiertas()->orderBy('nombre')->pluck('nombre', 'clave');

        return [
            Fieldset::make('Cuánto tiempo')->columns(2)->schema([
                Select::make('comparacion')->label('Deben pasar')->options(ComparacionPlazo::class)
                    ->default(ComparacionPlazo::AlMenos)->required()->selectablePlaceholder(false),
                TextInput::make('anios_minimos')->label('Años desde la inhumación')->numeric()->integer()
                    ->minValue(0)->maxValue(99)->suffix('años')->required(),
            ]),
            Fieldset::make('A quién aplica')->columns(2)->schema([
                Select::make('motivo')->label('Motivo de la exhumación')->options(MotivoExhumacion::class)
                    ->placeholder('Cualquier motivo')->helperText('Déjalo en «Cualquier motivo» si la regla no depende de él.'),
                Select::make('regimen_clave')->label('Tipo de título de la fosa')->options($regimenes)
                    ->placeholder('Cualquiera, o sin título'),
                TextInput::make('edad_minima')->label('Edad al fallecer: desde')->numeric()->integer()->minValue(0)->maxValue(130)
                    ->suffix('años')->helperText('Vacío si no importa la edad.'),
                TextInput::make('edad_maxima')->label('Edad al fallecer: hasta')->numeric()->integer()->minValue(0)->maxValue(130)
                    ->suffix('años')->gte('edad_minima')->validationMessages(['gte' => 'Debe ser igual o mayor que la edad «desde».'])
                    ->helperText('Incluye esa edad. Vacío si no hay límite.'),
                Toggle::make('solo_panteon_saturado')->label('Sólo cuando el panteón está saturado')
                    ->helperText('Por ejemplo, para reutilizar una fosa (art. 105).')->columnSpanFull(),
            ]),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('descripcion')->label('Nombre de la regla')
                ->helperText('Cómo la reconocerá el personal, por ejemplo «Plazo sanitario para menores de 15 años».')
                ->required()->maxLength(255),
            ...self::camposDeLaRegla(),
            Grid::make(2)->schema([
                TextInput::make('fundamento')->label('Fundamento')->helperText('Artículo que la establece.')->required()->maxLength(255),
                DatePicker::make('vigente_desde')->label('Aplica a partir del')->default(now())->required(),
            ]),
        ]);
    }

    /** La regla en una frase: «Al menos 6 años · si falleció con 15 años o más». */
    public static function enPalabras(ReglaPlazo $r): string
    {
        $partes = array_values(array_filter([
            $r->motivo ? 'si el motivo es «'.mb_strtolower($r->motivo->getLabel()).'»' : null,
            $r->regimen_clave ? 'si la fosa tiene título de '.mb_strtolower(Regimen::where('clave', $r->regimen_clave)->latest('vigente_desde')->value('nombre') ?? $r->regimen_clave) : null,
            match (true) {
                $r->edad_minima !== null && $r->edad_maxima !== null => "si falleció entre los {$r->edad_minima} y los {$r->edad_maxima} años",
                $r->edad_minima !== null => "si falleció con {$r->edad_minima} años o más",
                $r->edad_maxima !== null => 'si falleció con menos de '.($r->edad_maxima + 1).' años',
                default => null,
            },
            $r->solo_panteon_saturado ? 'sólo si el panteón está saturado' : null,
        ]));

        return $partes === [] ? 'Aplica a todos los finados.' : 'Aplica '.implode(', ', $partes).'.';
    }

    public static function plazo(ReglaPlazo $r): string
    {
        return $r->comparacion->getLabel().' '.Formato::cantidad($r->anios_minimos, 'año');
    }

    public static function table(Table $table): Table
    {
        $valor = fn (ReglaPlazo $r): string => self::plazo($r).'. '.self::enPalabras($r);

        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('descripcion')->label('Regla')->weight('semibold')->wrap()->width('40%')
                    ->description(fn (ReglaPlazo $r) => self::enPalabras($r)),
                TextColumn::make('anios_minimos')->label('Plazo')->state(fn (ReglaPlazo $r) => self::plazo($r))
                    ->extraAttributes(['style' => 'white-space:nowrap'])
                    ->description(fn (ReglaPlazo $r) => AccionesVersion::descripcionProgramada($r, $valor) ?? AccionesVersion::descripcionFin($r)),
                TextColumn::make('fundamento')->label('Fundamento')->wrap()->limit(60)->tooltip(fn (ReglaPlazo $r) => $r->fundamento),
            ])
            ->filters([
                TernaryFilter::make('aplican')
                    ->label('Mostrar')
                    ->placeholder('Todas, también las que ya no aplican')
                    ->trueLabel('Sólo las que aplican')
                    ->falseLabel('Sólo las que ya no aplican')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->queAplican(),
                        false: fn (Builder $query) => $query->whereNotNull('vigente_hasta')->whereDate('vigente_hasta', '<', today()),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->emptyStateHeading('No hay reglas de plazo')
            ->emptyStateDescription('Sin reglas, el sistema no autoriza ninguna exhumación. Captura las del reglamento.')
            ->recordActions(AccionesVersion::fila(
                AccionesVersion::cambiar('Cambiar regla', self::camposDeLaRegla(),
                    ['comparacion', 'anios_minimos', 'motivo', 'regimen_clave', 'edad_minima', 'edad_maxima', 'solo_panteon_saturado']),
                [
                    AccionesVersion::corregirTexto([
                        TextInput::make('descripcion')->label('Nombre de la regla')->required()->maxLength(255),
                        TextInput::make('fundamento')->label('Fundamento')->required()->maxLength(255),
                    ]),
                    AccionesVersion::dejarDeAplicar('Dejar de aplicar', 'Después de esa fecha, esta regla ya no cuenta para autorizar exhumaciones.'),
                    AccionesVersion::deshacer(),
                    AccionesVersion::historial($valor),
                ],
            ));
    }

    public static function getPages(): array
    {
        return ['index' => ManagePlazos::route('/')];
    }
}
