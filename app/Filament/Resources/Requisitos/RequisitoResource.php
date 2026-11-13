<?php

namespace App\Filament\Resources\Requisitos;

use App\Enums\MultiplicadorRequisito;
use App\Enums\ParteTramite;
use App\Enums\TipoTramite;
use App\Filament\Resources\Recurso;
use App\Filament\Resources\Requisitos\Pages\ManageRequisitos;
use App\Filament\Support\AccionesVersion;
use App\Models\Requisito;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Requisitos de cada trámite. Ventanilla los ve como lista de cotejo; si el reglamento cambia,
 * aquí se agrega, se cambia o se deja de pedir un documento, con la fecha en que aplica.
 * Los trámites ya iniciados conservan los requisitos con que empezaron.
 */
class RequisitoResource extends Recurso
{
    protected static ?string $model = Requisito::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Reglamento';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $modelLabel = 'requisito';

    protected static ?string $pluralModelLabel = 'requisitos de los trámites';

    protected static ?string $navigationLabel = 'Requisitos';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        // Agrupados por trámite en el orden del menú de trámites, y dentro de cada uno en el orden de la lista de cotejo.
        $orden = implode(',', array_map(fn (TipoTramite $t) => "'{$t->value}'", TipoTramite::cases()));

        return parent::getEloquentQuery()->ultimas()->orderByRaw("FIELD(tipo_tramite, {$orden})")->orderBy('orden');
    }

    /** Lo que decide qué se exige: cambia con versión nueva. */
    public static function camposQueDeciden(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('de_quien')->label('Lo presenta')->options(ParteTramite::class)->required(),
                Toggle::make('obligatorio')->label('Es obligatorio')->default(true)->inline(false)
                    ->helperText('Si lo apagas, se pide pero no impide continuar.'),
                TextInput::make('cantidad')->label('Cuántos')->numeric()->integer()->minValue(1)->maxValue(20)->default(1)->required(),
                TextInput::make('vigencia_maxima_dias')->label('Antigüedad máxima del documento')->numeric()->integer()->minValue(1)->suffix('días')
                    ->helperText('Por ejemplo, 90 para un comprobante de domicilio. Vacío si no caduca.'),
            ]),
            Section::make('Opciones especiales')
                ->description('Casi nunca se usan. Ábrelas sólo si el reglamento lo pide.')
                ->collapsed()
                ->columns(2)
                ->schema([
                    Select::make('se_multiplica_por')->label('Se pide más de una vez')->options(MultiplicadorRequisito::class)
                        ->placeholder('No, la cantidad es fija'),
                    TextInput::make('grupo_alternativa')->label('Se puede sustituir por otro')->maxLength(60)
                        ->helperText('Escribe la misma palabra en los documentos que se sustituyen entre sí, por ejemplo «identificacion». Basta con presentar uno.'),
                    Toggle::make('activa_pago_derechos')->label('Comprueba el pago de derechos al corriente')
                        ->helperText('Al cotejarlo, se marca que la persona está al corriente con Tesorería.'),
                    Toggle::make('exento_con_orden_autoridad')->label('No se pide si hay orden de autoridad')
                        ->helperText('Para exhumaciones o inhumaciones ordenadas por un juez o la fiscalía.'),
                ]),
        ];
    }

    /** Redacción: se corrige sin versión nueva. */
    public static function camposDeTexto(): array
    {
        return [
            TextInput::make('documento')->label('Documento')->required()->maxLength(200),
            Textarea::make('descripcion')->label('Explicación para el ciudadano')->rows(2)->maxLength(500)
                ->helperText('Opcional. Qué debe decir el documento o dónde se obtiene.'),
            Grid::make(2)->schema([
                TextInput::make('presentacion')->label('Cómo se entrega')->maxLength(100)
                    ->datalist(['Original', 'Original y 1 copia', 'Original y 2 copias', 'Original para cotejo y 1 copia', '1 copia', '2 copias', 'Copia simple y legible'])
                    ->helperText('Por ejemplo «Original y 1 copia».'),
                TextInput::make('fundamento')->label('Fundamento')->maxLength(255),
            ]),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('tipo_tramite')->label('Trámite')->options(TipoTramite::class)->required(),
            ...self::camposDeTexto(),
            ...self::camposQueDeciden(),
            DatePicker::make('vigente_desde')->label('Se pide a partir del')->default(now())->required()
                ->helperText('Los trámites ya iniciados no lo piden.'),
        ]);
    }

    public static function quien(Requisito $r): string
    {
        $cantidad = $r->se_multiplica_por?->getLabel() ?? ($r->cantidad > 1 ? "{$r->cantidad} documentos" : null);

        return $r->de_quien->getLabel().($cantidad ? " · {$cantidad}" : '');
    }

    public static function table(Table $table): Table
    {
        $valor = fn (Requisito $r): string => $r->documento.' — '.self::quien($r).($r->obligatorio ? '' : ' (opcional)');

        return $table
            ->paginated(false)
            ->defaultGroup(Group::make('tipo_tramite')->label('Trámite')->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Requisito $r) => $r->tipo_tramite->getLabel()))
            ->groupingSettingsHidden()
            ->columns([
                TextColumn::make('documento')->label('Documento')->weight('semibold')->wrap()->width('45%')
                    ->description(fn (Requisito $r) => $r->descripcion),
                TextColumn::make('de_quien')->label('Lo presenta')->state(fn (Requisito $r) => self::quien($r))->wrap()
                    ->description(fn (Requisito $r) => AccionesVersion::descripcionProgramada($r, $valor) ?? AccionesVersion::descripcionFin($r)),
                TextColumn::make('presentacion')->label('Cómo se entrega')->placeholder('—')->wrap(),
                // Sólo se marca la excepción: casi todos son obligatorios y una etiqueta repetida no se lee.
                TextColumn::make('nota')->label('')->badge()
                    ->state(fn (Requisito $r) => $r->emitido_por_sistema ? 'Lo emite el sistema' : ($r->obligatorio ? null : 'Opcional'))
                    ->color(fn (Requisito $r) => $r->emitido_por_sistema ? 'info' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('tipo_tramite')->label('Trámite')->options(TipoTramite::class),
                TernaryFilter::make('aplican')
                    ->label('Mostrar')
                    ->placeholder('Todos, también los que ya no se piden')
                    ->trueLabel('Sólo los que se piden')
                    ->falseLabel('Sólo los que ya no se piden')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->queAplican(),
                        false: fn (Builder $query) => $query->whereNotNull('vigente_hasta')->whereDate('vigente_hasta', '<', today()),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->emptyStateHeading('No hay requisitos con estos filtros')
            ->recordActions(AccionesVersion::fila(
                AccionesVersion::cambiar('Cambiar requisito', self::camposQueDeciden(),
                    ['de_quien', 'obligatorio', 'cantidad', 'vigencia_maxima_dias', 'se_multiplica_por', 'grupo_alternativa', 'activa_pago_derechos', 'exento_con_orden_autoridad']),
                [
                    AccionesVersion::corregirTexto(self::camposDeTexto()),
                    AccionesVersion::dejarDeAplicar('Dejar de pedirlo', 'Los trámites que se inicien después de esa fecha ya no lo pedirán.'),
                    AccionesVersion::deshacer(),
                    AccionesVersion::historial($valor),
                ],
            ));
    }

    public static function getPages(): array
    {
        return ['index' => ManageRequisitos::route('/')];
    }
}
