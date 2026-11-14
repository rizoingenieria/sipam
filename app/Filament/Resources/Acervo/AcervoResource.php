<?php

namespace App\Filament\Resources\Acervo;

use App\Filament\Resources\Acervo\Pages\EditarDocumento;
use App\Filament\Resources\Acervo\Pages\ListAcervo;
use App\Filament\Resources\Acervo\Pages\NuevoDocumento;
use App\Filament\Resources\Acervo\Pages\VerDocumento;
use App\Filament\Resources\Recurso;
use App\Filament\Support\CamposEspacio;
use App\Models\DocumentoAcervo;
use App\Models\Inhumado;
use App\Models\TipoDocumentoAcervo;
use App\Models\Titulo;
use App\Services\Acervo\AcervoHistorico;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Acervo histórico: los originales en papel (libros, títulos antiguos, actas, planos) escaneados y guardados
 * tal cual. Sin OCR: se encuentran por los datos que se capturan (qué es, de cuándo, dónde está el papel)
 * y por los espacios, títulos y personas a los que se ligan.
 */
class AcervoResource extends Recurso
{
    protected static ?string $model = DocumentoAcervo::class;

    protected static ?string $slug = 'acervo';

    protected static string|\UnitEnum|null $navigationGroup = 'Registros';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $modelLabel = 'documento del acervo';

    protected static ?string $pluralModelLabel = 'acervo histórico';

    protected static ?string $navigationLabel = 'Acervo histórico';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('tipo')->withCount(['archivos', 'espacios', 'titulos', 'inhumados']);
    }

    /** Formulario de alta y de corrección. Los archivos sólo van en el alta; después se agregan desde la ficha. */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Qué es')
                ->description('Escríbelo como lo buscarías después: no hay lectura automática del texto escaneado.')
                ->schema([
                    Select::make('tipo_id')->label('Tipo de documento')->required()
                        ->options(fn () => TipoDocumentoAcervo::where('activo', true)->orderBy('orden')->pluck('nombre', 'id')),
                    TextInput::make('titulo')->label('Nombre del documento')->required()->maxLength(255)
                        ->placeholder('Ej.: Libro 3 de inhumaciones, fojas 45 a 52'),
                    Textarea::make('descripcion')->label('Qué contiene')->rows(3)->maxLength(5000)
                        ->placeholder('Ej.: Registro de inhumaciones de la sección A, lotes 1 al 40, de marzo a junio de 1948.'),
                ]),
            Section::make('De cuándo es y dónde está el papel')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('anio_desde')->label('Año (o desde)')->numeric()->minValue(1800)->maxValue((int) now()->year)
                            ->helperText('Si no se sabe con exactitud, escribe el más probable.'),
                        TextInput::make('anio_hasta')->label('Hasta (si abarca varios años)')->numeric()->minValue(1800)->maxValue((int) now()->year),
                    ]),
                    TextInput::make('ubicacion_fisica')->label('Dónde está el original')->maxLength(255)
                        ->placeholder('Ej.: Archivo municipal, caja 12, libro 3, fojas 45 a 52')
                        ->helperText('Para encontrar el papel cuando haga falta.'),
                    Textarea::make('observaciones')->label('Observaciones')->rows(2)->maxLength(5000)
                        ->placeholder('Ej.: Hojas manchadas por humedad; la foja 47 está incompleta.'),
                ]),
            Section::make('Archivos escaneados')
                ->visibleOn('create')
                ->schema([
                    FileUpload::make('archivos')->hiddenLabel()->required()
                        ->multiple()->reorderable()->appendFiles()->storeFiles(false)
                        // De uno en uno: Filament guarda en el orden en que terminan de subir; así coincide con el que se ve.
                        ->maxParallelUploads(1)
                        ->acceptedFileTypes(array_keys(AcervoHistorico::TIPOS))
                        ->maxSize(fn () => app(AcervoHistorico::class)->tamanoMaximoMb() * 1024)
                        ->helperText(fn () => 'Uno o varios archivos ('.app(AcervoHistorico::class)->tiposEnPalabras().') de hasta '
                            .app(AcervoHistorico::class)->tamanoMaximoMb().' MB cada uno. Arrástralos en el orden de las páginas; puedes reacomodarlos. Se guardan tal cual, sin cambiarlos.'),
                ]),
            Section::make('A qué se refiere')
                ->description('Liga el documento a los espacios, títulos o personas que menciona. Así aparece en sus fichas. Puedes dejarlo sin ligar y hacerlo después.')
                ->schema([
                    Select::make('espacios')->label('Espacios (fosas, nichos, criptas)')->multiple()
                        ->searchable()->getSearchResultsUsing(fn (string $search) => CamposEspacio::buscar($search))
                        ->getOptionLabelsUsing(fn (array $values) => self::etiquetasEspacios($values))
                        ->helperText('Busca por clave (MUN-A-12-1), lote, titular o persona sepultada.'),
                    Select::make('titulos')->label('Títulos')->multiple()
                        ->searchable()->getSearchResultsUsing(fn (string $search) => self::buscarTitulos($search))
                        ->getOptionLabelsUsing(fn (array $values) => Titulo::with('titulares')->whereKey($values)->get()->mapWithKeys(fn (Titulo $t) => [$t->id => self::etiquetaTitulo($t)])->all())
                        ->helperText('Busca por folio o por nombre del titular.'),
                    Select::make('inhumados')->label('Personas sepultadas')->multiple()
                        ->searchable()->getSearchResultsUsing(fn (string $search) => self::buscarInhumados($search))
                        ->getOptionLabelsUsing(fn (array $values) => Inhumado::with('espacio')->whereKey($values)->get()->mapWithKeys(fn (Inhumado $i) => [$i->id => self::etiquetaInhumado($i)])->all())
                        ->helperText('Busca por nombre.'),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (DocumentoAcervo $d) => static::getUrl('view', ['record' => $d]))
            ->columns([
                TextColumn::make('titulo')->label('Documento')->weight('semibold')->wrap()
                    ->description(fn (DocumentoAcervo $d) => collect([$d->folio, $d->tipo?->nombre, $d->periodo()])->filter()->implode(' · ')),
                TextColumn::make('ubicacion_fisica')->label('Dónde está el papel')->wrap()->placeholder('Sin registrar'),
                TextColumn::make('vinculos')->label('Ligado a')
                    ->state(fn (DocumentoAcervo $d) => self::resumenVinculos($d))->placeholder('Sin ligar')->wrap(),
                TextColumn::make('archivos_count')->label('Archivos')->alignEnd(),
                TextColumn::make('retirado_en')->label('')->badge()->color('danger')
                    ->formatStateUsing(fn ($state) => $state ? 'Retirado' : null),
            ])
            ->searchable()
            ->searchUsing(fn (Builder $query, string $search) => self::buscar($query, $search))
            ->searchPlaceholder('Nombre, folio, caja o libro, persona, titular o espacio')
            ->filters([
                SelectFilter::make('tipo_id')->label('Tipo')
                    ->options(fn () => TipoDocumentoAcervo::orderBy('orden')->pluck('nombre', 'id')),
                Filter::make('periodo')->label('Años')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('desde')->label('Desde el año')->numeric(),
                            TextInput::make('hasta')->label('Hasta el año')->numeric(),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->delPeriodo(
                        filled($data['desde'] ?? null) ? (int) $data['desde'] : null,
                        filled($data['hasta'] ?? null) ? (int) $data['hasta'] : null,
                    ))
                    ->indicateUsing(fn (array $data) => collect([
                        filled($data['desde'] ?? null) ? 'Desde '.$data['desde'] : null,
                        filled($data['hasta'] ?? null) ? 'Hasta '.$data['hasta'] : null,
                    ])->filter()->implode(' · ') ?: null),
                Filter::make('sin_ligar')->label('Sólo los que falta ligar')->toggle()
                    ->query(fn (Builder $query) => $query->doesntHave('espacios')->doesntHave('titulos')->doesntHave('inhumados')),
                // Sin este filtro, el listado oculta los retirados (ver ListAcervo::getTableQuery()).
                Filter::make('retirados')->label('Mostrar retirados')->toggle()->query(fn (Builder $query) => $query),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->emptyStateHeading('No hay documentos que coincidan')
            ->emptyStateDescription('Revisa la búsqueda o los filtros. Los documentos se registran con el botón «Registrar documento».')
            ->recordActions([ViewAction::make()->label('Abrir')]);
    }

    /** Busca en los datos capturados y en lo que está ligado (sin OCR, es lo único que hay para buscar). */
    public static function buscar(Builder $query, string $texto): Builder
    {
        $texto = trim($texto);
        $like = '%'.$texto.'%';

        return $query->where(fn (Builder $q) => $q
            ->where('folio', 'like', $like)
            ->orWhere('titulo', 'like', $like)
            ->orWhere('descripcion', 'like', $like)
            ->orWhere('ubicacion_fisica', 'like', $like)
            ->orWhere('observaciones', 'like', $like)
            ->orWhereHas('inhumados', fn (Builder $i) => $i->where('nombre_completo', 'like', $like))
            ->orWhereHas('titulos', fn (Builder $t) => $t->where('folio', 'like', $like)
                ->orWhereHas('titulares', fn (Builder $p) => $p->where('nombre_completo', 'like', $like)))
            ->orWhereHas('espacios', fn (Builder $e) => $e->whereIn('espacios.id', array_keys(CamposEspacio::buscar($texto, 200)))));
    }

    public static function resumenVinculos(DocumentoAcervo $d): ?string
    {
        return collect([
            $d->espacios_count ? $d->espacios_count.' '.($d->espacios_count === 1 ? 'espacio' : 'espacios') : null,
            $d->titulos_count ? $d->titulos_count.' '.($d->titulos_count === 1 ? 'título' : 'títulos') : null,
            $d->inhumados_count ? $d->inhumados_count.' '.($d->inhumados_count === 1 ? 'persona' : 'personas') : null,
        ])->filter()->implode(' · ') ?: null;
    }

    /** @return array<int, string> */
    public static function etiquetasEspacios(array $ids): array
    {
        return collect($ids)->mapWithKeys(fn ($id) => [(int) $id => ($e = CamposEspacio::cargar($id)) ? CamposEspacio::etiqueta($e) : 'Espacio '.$id])->all();
    }

    /** @return array<int, string> */
    public static function buscarTitulos(string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return [];
        }

        return Titulo::with('titulares')
            ->where(fn (Builder $q) => $q->where('folio', 'like', "%{$texto}%")
                ->orWhereHas('titulares', fn (Builder $p) => $p->where('nombre_completo', 'like', "%{$texto}%")))
            ->limit(30)->get()
            ->mapWithKeys(fn (Titulo $t) => [$t->id => self::etiquetaTitulo($t)])->all();
    }

    public static function etiquetaTitulo(Titulo $t): string
    {
        return 'Título '.$t->folio.' — '.($t->titulares->pluck('nombre_completo')->join(', ') ?: 'sin titular');
    }

    /** @return array<int, string> */
    public static function buscarInhumados(string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return [];
        }

        return Inhumado::with('espacio')->where('nombre_completo', 'like', "%{$texto}%")
            ->orderBy('nombre_completo')->limit(30)->get()
            ->mapWithKeys(fn (Inhumado $i) => [$i->id => self::etiquetaInhumado($i)])->all();
    }

    public static function etiquetaInhumado(Inhumado $i): string
    {
        return $i->nombre_completo.($i->fecha_inhumacion ? ' (inhumado en '.$i->fecha_inhumacion->year.')' : '').($i->espacio ? ' — '.$i->espacio->clave_completa : '');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAcervo::route('/'),
            'create' => NuevoDocumento::route('/nuevo'),
            'view' => VerDocumento::route('/{record}'),
            'edit' => EditarDocumento::route('/{record}/corregir'),
        ];
    }
}
