<?php

namespace App\Filament\Resources\Bitacora;

use App\Enums\EventoBitacora;
use App\Filament\Resources\Bitacora\Pages\ListBitacora;
use App\Filament\Resources\Recurso;
use App\Models\Bitacora;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Visor de la bitácora para Contraloría y Jurídico: qué cambió, quién, cuándo y desde dónde. */
class BitacoraResource extends Recurso
{
    protected static ?string $model = Bitacora::class;

    protected static ?string $slug = 'bitacora';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $modelLabel = 'registro de la bitácora';

    protected static ?string $pluralModelLabel = 'bitácora de cambios';

    protected static ?string $navigationLabel = 'Bitácora de cambios';

    protected static ?int $navigationSort = 30;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('usuario'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Cuándo')
                    ->formatStateUsing(fn ($state) => Formato::fecha($state).', '.$state->format('H:i:s')),
                TextColumn::make('usuario.name')->label('Quién')->placeholder('El sistema')
                    ->description(fn (Bitacora $b) => $b->ip),
                TextColumn::make('evento')->label('Qué pasó')->badge(),
                TextColumn::make('descripcion')->label('Registro')->wrap()->searchable()
                    ->description(fn (Bitacora $b) => self::resumen($b)),
            ])
            ->searchPlaceholder('Buscar en el registro (folio, nombre…)')
            ->filters([
                Filter::make('periodo')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['desde'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['hasta'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d)))
                    ->indicateUsing(fn (array $data) => collect([
                        ($data['desde'] ?? null) ? 'Desde '.$data['desde'] : null,
                        ($data['hasta'] ?? null) ? 'Hasta '.$data['hasta'] : null,
                    ])->filter()->all()),
                SelectFilter::make('usuario_id')->label('Quién')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))->searchable(),
                SelectFilter::make('evento')->label('Qué pasó')->options(EventoBitacora::class),
                SelectFilter::make('modelo_tipo')->label('Tipo de registro')->options(Auditoria::MODELOS),
            ])
            ->filtersFormColumns(2)
            ->emptyStateHeading('No hay movimientos con esos filtros')
            ->recordActions([
                Action::make('ver')
                    ->label('Ver cambios')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (Bitacora $record) => $record->descripcion)
                    ->modalDescription(fn (Bitacora $record) => $record->evento->getLabel().' · '.Formato::fecha($record->created_at).', '.$record->created_at->format('H:i:s')
                        .' · '.($record->usuario?->name ?? 'El sistema').($record->ip ? ' · IP '.$record->ip : ''))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (Bitacora $record) => view('filament.bitacora-cambios', ['cambios' => $record->cambios ?? []]))
                    ->visible(fn (Bitacora $record) => ! empty($record->cambios)),
            ]);
    }

    /** «Cambió importe y acuerdo de Cabildo». */
    public static function resumen(Bitacora $b): ?string
    {
        if (empty($b->cambios) || $b->evento !== EventoBitacora::Modificado) {
            return null;
        }
        $campos = array_map(fn ($c) => str_replace('_', ' ', $c), array_keys($b->cambios));

        return 'Cambió: '.implode(', ', array_slice($campos, 0, 4)).(count($campos) > 4 ? ' y '.(count($campos) - 4).' más' : '');
    }

    public static function getPages(): array
    {
        return ['index' => ListBitacora::route('/')];
    }
}
