<?php

namespace App\Filament\Resources\Tramites;

use App\Enums\EstadoTramite;
use App\Enums\TipoTramite;
use App\Filament\Resources\Recurso;
use App\Filament\Resources\Tramites\Pages\CapturarTramite;
use App\Filament\Resources\Tramites\Pages\ListTramites;
use App\Filament\Resources\Tramites\Pages\VerTramite;
use App\Filament\Resources\Tramites\RelationManagers\ArchivosRelationManager;
use App\Filament\Resources\Tramites\RelationManagers\HistorialRelationManager;
use App\Filament\Resources\Tramites\RelationManagers\OrdenesRelationManager;
use App\Filament\Resources\Tramites\RelationManagers\ParticipantesRelationManager;
use App\Filament\Resources\Tramites\RelationManagers\RequisitosRelationManager;
use App\Models\Tramite;
use App\Services\Tramites\GuiaTramite;
use App\Support\Formato;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class TramiteResource extends Recurso
{
    protected static ?string $model = Tramite::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Trámites';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?string $modelLabel = 'trámite';

    protected static ?string $pluralModelLabel = 'trámites';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'folio';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['espacio.lote.seccion.panteon', 'solicitante']);
    }

    public static function ubicacion(Tramite $t): string
    {
        return $t->espacio->clave_completa;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (Tramite $t) => static::getUrl('view', ['record' => $t]))
            ->columns([
                TextColumn::make('folio')->label('Folio')->weight('semibold')->searchable()
                    ->description(fn (Tramite $t) => $t->tipo->getLabel()),
                TextColumn::make('solicitante.nombre_completo')->label('Solicitante')->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $w) => $w
                        ->whereHas('solicitante', fn (Builder $q) => $q->where('nombre_completo', 'like', "%{$search}%"))
                        ->orWhereHas('espacio', fn (Builder $e) => $e->whereRaw(
                            "(SELECT CONCAT_WS('-', panteones.clave, secciones.clave, lotes.numero, espacios.numero) FROM lotes JOIN secciones ON secciones.id = lotes.seccion_id JOIN panteones ON panteones.id = secciones.panteon_id WHERE lotes.id = espacios.lote_id) LIKE ?",
                            ["%{$search}%"]))))
                    ->description(fn (Tramite $t) => self::ubicacion($t)),
                TextColumn::make('estado')->label('Estado')->badge(),
                TextColumn::make('siguiente')->label('Qué sigue')->wrap()
                    ->state(fn (Tramite $t) => GuiaTramite::siguiente($t->estado, $t->tipo))
                    ->description(fn (Tramite $t) => $t->caduca_en && ! $t->estado->esTerminal()
                        ? ($t->caduca_en->lt(Carbon::today()->addDays(30)) ? '⚠ Caduca el '.Formato::fecha($t->caduca_en) : 'Caduca el '.Formato::fecha($t->caduca_en))
                        : null),
                TextColumn::make('created_at')->label('Capturado')->formatStateUsing(fn ($state) => Formato::fecha($state)),
            ])
            ->searchPlaceholder('Folio, solicitante o espacio')
            ->filters([
                SelectFilter::make('tipo')->label('Trámite')->options(TipoTramite::class),
                SelectFilter::make('estado')->label('Estado')->options(EstadoTramite::class),
            ])
            ->emptyStateHeading('No hay trámites aquí')
            ->emptyStateDescription('Cambia de pestaña o captura un trámite nuevo.')
            ->recordActions([ViewAction::make()->label('Abrir')]);
    }

    public static function getRelations(): array
    {
        return [
            RequisitosRelationManager::class,
            ParticipantesRelationManager::class,
            ArchivosRelationManager::class,
            OrdenesRelationManager::class,
            HistorialRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTramites::route('/'),
            'create' => CapturarTramite::route('/nuevo'),
            'view' => VerTramite::route('/{record}'),
        ];
    }
}
