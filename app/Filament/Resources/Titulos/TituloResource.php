<?php

namespace App\Filament\Resources\Titulos;

use App\Enums\EstadoTitulo;
use App\Filament\Resources\Recurso;
use App\Filament\Resources\Titulos\Pages\ListTitulos;
use App\Filament\Resources\Titulos\Pages\VerTitulo;
use App\Models\Titulo;
use App\Support\Formato;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Títulos de derecho de uso: a nombre de quién está cada espacio y hasta cuándo. */
class TituloResource extends Recurso
{
    protected static ?string $model = Titulo::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Registros';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $modelLabel = 'título';

    protected static ?string $pluralModelLabel = 'títulos';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['espacio.lote.seccion.panteon', 'regimen', 'titulares']);
    }

    public static function vence(Titulo $t): string
    {
        return $t->regimen->duracion_anios >= 99 ? 'No vence' : Formato::fecha($t->fecha_vencimiento);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (Titulo $t) => static::getUrl('view', ['record' => $t]))
            ->columns([
                TextColumn::make('folio')->label('Título')->weight('semibold')
                    ->description(fn (Titulo $t) => $t->regimen->nombre)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('titulos.folio', 'like', "%{$search}%")
                        ->orWhereHas('personas', fn (Builder $p) => $p->where('nombre_completo', 'like', "%{$search}%"))
                        ->orWhereHas('espacio', fn (Builder $e) => $e->whereRaw(
                            "(SELECT CONCAT_WS('-', panteones.clave, secciones.clave, lotes.numero, espacios.numero) FROM lotes JOIN secciones ON secciones.id = lotes.seccion_id JOIN panteones ON panteones.id = secciones.panteon_id WHERE lotes.id = espacios.lote_id) LIKE ?",
                            ["%{$search}%"])))),
                TextColumn::make('titulares')->label('A nombre de')->wrap()
                    ->state(fn (Titulo $t) => $t->titulares->pluck('nombre_completo')->implode(', ') ?: 'Sin titular registrado'),
                TextColumn::make('espacio_id')->label('Espacio')->state(fn (Titulo $t) => $t->espacio->clave_completa),
                TextColumn::make('estado')->label('Estado')->badge(),
                TextColumn::make('fecha_vencimiento')->label('Vence')->state(fn (Titulo $t) => self::vence($t)),
            ])
            ->searchPlaceholder('Folio, nombre del titular o clave del espacio')
            ->filters([
                SelectFilter::make('estado')->label('Estado')->options(EstadoTitulo::class)->default(EstadoTitulo::Vigente->value),
                Filter::make('por_vencer')->label('Vencen en los próximos 90 días')
                    ->query(fn (Builder $query) => $query->porVencer(90)),
            ])
            ->emptyStateHeading('No se encontraron títulos')
            ->recordActions([ViewAction::make()->label('Abrir')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTitulos::route('/'),
            'view' => VerTitulo::route('/{record}'),
        ];
    }
}
