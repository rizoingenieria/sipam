<?php

namespace App\Filament\Resources\Espacios;

use App\Enums\EstadoTitulo;
use App\Enums\Permiso;
use App\Enums\TipoEspacio;
use App\Filament\Resources\Acervo\AcervoResource;
use App\Filament\Resources\Espacios\Pages\ManageEspacios;
use App\Filament\Resources\Recurso;
use App\Models\Espacio;
use App\Models\Panteon;
use App\Models\Seccion;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Espacios (fosas, nichos, criptas, gavetas) de todos los panteones. El alta masiva se hace con
 * «Generar lotes» en cada sección; aquí se busca un espacio, se corrigen sus datos o se agrega uno suelto.
 */
class EspacioResource extends Recurso
{
    protected static ?string $model = Espacio::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $modelLabel = 'espacio';

    protected static ?string $pluralModelLabel = 'espacios';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['lote.seccion.panteon', 'tituloVigente'])
            ->join('lotes', 'lotes.id', '=', 'espacios.lote_id')
            ->join('secciones', 'secciones.id', '=', 'lotes.seccion_id')
            ->join('panteones', 'panteones.id', '=', 'secciones.panteon_id')
            ->select('espacios.*')
            // Lote 2 antes que lote 10: los números se guardan como texto.
            ->orderBy('panteones.nombre')->orderBy('secciones.clave')
            ->orderByRaw('CAST(lotes.numero AS UNSIGNED), lotes.numero')
            ->orderByRaw('CAST(espacios.numero AS UNSIGNED), espacios.numero');
    }

    public static function opcionesSeccion(?int $panteonId = null): array
    {
        return Seccion::with('panteon')->when($panteonId, fn ($q) => $q->where('panteon_id', $panteonId))
            ->orderBy('panteon_id')->orderBy('clave')->get()
            ->mapWithKeys(fn (Seccion $s) => [$s->id => ($panteonId ? '' : "{$s->panteon->nombre}, ")."sección {$s->clave}".($s->nombre ? " ({$s->nombre})" : '')])
            ->all();
    }

    /** Datos físicos y marcas del espacio: iguales al crear y al editar. */
    public static function camposDelEspacio(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('tipo')->label('Tipo de espacio')->options(TipoEspacio::class)->default(TipoEspacio::Fosa)->required(),
            ]),
            Fieldset::make('Medidas')->columns(3)->schema([
                TextInput::make('largo_m')->label('Largo')->numeric()->minValue(0)->maxValue(99)->suffix('m'),
                TextInput::make('ancho_m')->label('Ancho')->numeric()->minValue(0)->maxValue(99)->suffix('m'),
                TextInput::make('profundidad_m')->label('Profundidad')->numeric()->minValue(0)->maxValue(99)->suffix('m'),
            ]),
            Toggle::make('habilitado')->label('Disponible para usarse')->default(true)
                ->helperText('Apágalo si el espacio no puede usarse (derrumbe, invasión, obra pendiente).'),
            Toggle::make('es_fosa_comun')->label('Es fosa común')->helperText('Arts. 46 y 90.'),
            Toggle::make('es_monumento_historico')->label('Es monumento histórico')->helperText('Anterior a 1931 (art. 86).'),
            Textarea::make('observaciones')->label('Observaciones')->rows(2)->helperText('Opcional.'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('clave_completa')->label('Espacio')->weight('semibold')
                    ->description(fn (Espacio $e) => "{$e->lote->seccion->panteon->nombre} · sección {$e->lote->seccion->clave} · lote {$e->lote->numero} · {$e->tipo->getLabel()} {$e->numero}")
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('lotes.numero', trim($search))
                        ->orWhereRaw("CONCAT_WS('-', panteones.clave, secciones.clave, lotes.numero, espacios.numero) LIKE ?", ['%'.trim($search).'%']))),
                TextColumn::make('tipo')->label('Tipo')->badge(),
                TextColumn::make('situacion')->label('Situación')->badge()
                    ->state(fn (Espacio $e) => match (true) {
                        ! $e->habilitado => 'No disponible',
                        $e->tituloVigente !== null => 'Con título',
                        default => 'Sin título',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'No disponible' => 'danger',
                        'Con título' => 'success',
                        default => 'gray',
                    })
                    ->description(fn (Espacio $e) => collect([
                        $e->es_fosa_comun ? 'Fosa común' : null,
                        $e->es_monumento_historico ? 'Monumento histórico' : null,
                    ])->filter()->implode(' · ') ?: null),
            ])
            ->searchPlaceholder('Buscar lote o clave')
            ->filters([
                SelectFilter::make('panteon')->label('Panteón')
                    ->options(fn () => Panteon::orderBy('nombre')->pluck('nombre', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value']) ? $query->where('panteones.id', $data['value']) : $query),
                SelectFilter::make('seccion')->label('Sección')
                    ->options(fn () => self::opcionesSeccion())
                    ->query(fn (Builder $query, array $data) => filled($data['value']) ? $query->where('secciones.id', $data['value']) : $query),
                SelectFilter::make('tipo')->label('Tipo')->options(TipoEspacio::class),
                SelectFilter::make('situacion')->label('Situación')
                    ->options(['con_titulo' => 'Con título', 'sin_titulo' => 'Sin título', 'no_disponible' => 'No disponible'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'con_titulo' => $query->whereHas('titulos', fn (Builder $t) => $t->where('estado', EstadoTitulo::Vigente->value)),
                        'sin_titulo' => $query->where('espacios.habilitado', true)->whereDoesntHave('titulos', fn (Builder $t) => $t->where('estado', EstadoTitulo::Vigente->value)),
                        'no_disponible' => $query->where('espacios.habilitado', false),
                        default => $query,
                    }),
            ])
            // A la vista, no detrás de un ícono: es la forma principal de encontrar un espacio.
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->emptyStateHeading('No hay espacios con esos datos')
            ->emptyStateDescription('Revisa los filtros. Para dar de alta muchos espacios a la vez, abre el panteón y usa «Generar lotes» en la sección.')
            ->recordActions([
                // Documentos escaneados del espacio (acervo histórico), para quien puede consultarlos.
                Action::make('acervo')->label(fn (Espacio $record) => 'Acervo'.(($n = $record->documentosAcervo()->count()) ? " ({$n})" : ''))
                    ->icon(Heroicon::OutlinedArchiveBox)->color('gray')
                    ->url(fn (Espacio $record) => AcervoResource::getUrl('index', ['espacio' => $record->id]))
                    ->visible(fn () => (bool) auth()->user()?->puede(Permiso::AcervoConsultar)),
                EditAction::make()->label('Editar')
                    ->modalHeading(fn (Espacio $record) => "Espacio {$record->clave_completa}")
                    ->modalSubmitActionLabel('Guardar espacio')
                    ->schema(self::camposDelEspacio()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEspacios::route('/')];
    }
}
