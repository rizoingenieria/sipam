<?php

namespace App\Filament\Resources\Finados;

use App\Enums\EstadoInhumado;
use App\Filament\Resources\Finados\Pages\ListFinados;
use App\Filament\Resources\Finados\Pages\VerFinado;
use App\Filament\Resources\Recurso;
use App\Models\Inhumado;
use App\Models\Panteon;
use App\Support\Formato;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Libro de inhumaciones: quién está sepultado dónde, desde cuándo y qué se ha hecho con sus restos. */
class FinadoResource extends Recurso
{
    protected static ?string $model = Inhumado::class;

    protected static ?string $slug = 'finados';

    protected static string|\UnitEnum|null $navigationGroup = 'Registros';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $modelLabel = 'finado';

    protected static ?string $pluralModelLabel = 'finados';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('espacio.lote.seccion.panteon');
    }

    public static function vida(Inhumado $i): ?string
    {
        return collect([
            $i->fecha_defuncion ? 'Falleció el '.Formato::fecha($i->fecha_defuncion) : null,
            $i->edad_al_fallecer !== null ? 'a los '.Formato::cantidad($i->edad_al_fallecer, 'año') : 'edad no registrada',
        ])->filter()->implode(' ');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('fecha_inhumacion', 'desc')
            ->recordUrl(fn (Inhumado $i) => static::getUrl('view', ['record' => $i]))
            ->columns([
                TextColumn::make('nombre_completo')->label('Nombre')->weight('semibold')->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('nombre_completo', 'like', "%{$search}%")->orWhere('numero_acta_defuncion', trim($search))))
                    ->description(fn (Inhumado $i) => self::vida($i)),
                TextColumn::make('espacio_id')->label('Dónde está')
                    ->state(fn (Inhumado $i) => $i->espacio?->clave_completa ?? 'Fuera del panteón')
                    ->description(fn (Inhumado $i) => $i->espacio?->lote->seccion->panteon->nombre),
                TextColumn::make('estado')->label('Situación')->badge(),
                TextColumn::make('fecha_inhumacion')->label('Inhumado')
                    ->formatStateUsing(fn ($state) => Formato::fecha($state))->placeholder('Sin fecha')
                    ->description(fn (Inhumado $i) => $i->fecha_inhumacion ? 'Hace '.Formato::cantidad($i->aniosSepultadoA(), 'año') : null),
            ])
            ->searchPlaceholder('Nombre o número de acta')
            ->filters([
                SelectFilter::make('estado')->label('Situación')->options(EstadoInhumado::class),
                SelectFilter::make('panteon')->label('Panteón')
                    ->options(fn () => Panteon::orderBy('nombre')->pluck('nombre', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('espacio.lote.seccion', fn (Builder $q) => $q->where('panteon_id', $data['value']))
                        : $query),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->emptyStateHeading('No se encontró a nadie')
            ->emptyStateDescription('Revisa cómo escribiste el nombre. Los finados se registran solos al concluir cada inhumación.')
            ->recordActions([ViewAction::make()->label('Abrir')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinados::route('/'),
            'view' => VerFinado::route('/{record}'),
        ];
    }
}
