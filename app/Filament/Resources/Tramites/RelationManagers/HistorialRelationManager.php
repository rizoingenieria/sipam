<?php

namespace App\Filament\Resources\Tramites\RelationManagers;

use App\Filament\Resources\Tramites\RelationManagers\Concerns\ParteDelExpediente;
use App\Models\TramiteHistorial;
use App\Support\Formato;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Cada cambio de estado, quién lo hizo y cuándo. No se edita ni se borra. */
class HistorialRelationManager extends RelationManager
{
    use ParteDelExpediente;

    protected static string $relationship = 'historial';

    protected static ?string $title = 'Historial';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedClock;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('usuario')->reorder('id', 'desc'))
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')->label('Cuándo')
                    ->formatStateUsing(fn ($state) => Formato::fecha($state).', '.$state->format('H:i')),
                TextColumn::make('a_estado')->label('Pasó a')->badge()
                    ->description(fn (TramiteHistorial $h) => $h->de_estado ? 'Antes: '.$h->de_estado->getLabel() : 'Captura'),
                TextColumn::make('usuario.name')->label('Quién')->placeholder('El sistema'),
                TextColumn::make('comentario')->label('Nota')->wrap()->placeholder('—'),
            ]);
    }
}
