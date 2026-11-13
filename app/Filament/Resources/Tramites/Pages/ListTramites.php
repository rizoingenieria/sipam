<?php

namespace App\Filament\Resources\Tramites\Pages;

use App\Enums\EstadoTramite;
use App\Filament\Resources\Tramites\TramiteResource;
use App\Models\Tramite;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListTramites extends ListRecords
{
    protected static string $resource = TramiteResource::class;

    public function getSubheading(): ?string
    {
        return 'Cada pestaña es una etapa. Abre un trámite para ver qué sigue y qué falta.';
    }

    /** Pestañas por etapa: así cada quien va directo a lo que le toca. */
    public function getTabs(): array
    {
        $etapa = fn (EstadoTramite ...$estados) => fn (Builder $query) => $query->whereIn('estado', array_map(fn ($e) => $e->value, $estados));
        $cuenta = fn (EstadoTramite ...$estados) => Tramite::whereIn('estado', array_map(fn ($e) => $e->value, $estados))->count();

        return [
            'en_curso' => Tab::make('En curso')
                ->modifyQueryUsing($etapa(...array_filter(EstadoTramite::cases(), fn ($e) => ! $e->esTerminal())))
                ->badge($cuenta(...array_filter(EstadoTramite::cases(), fn ($e) => ! $e->esTerminal()))),
            'revision' => Tab::make('Por revisar')
                ->modifyQueryUsing($etapa(EstadoTramite::Capturado, EstadoTramite::EnRevision, EstadoTramite::EnPublicacion))
                ->badge($cuenta(EstadoTramite::Capturado, EstadoTramite::EnRevision, EstadoTramite::EnPublicacion)),
            'por_pagar' => Tab::make('Por pagar')->modifyQueryUsing($etapa(EstadoTramite::PorPagar))->badge($cuenta(EstadoTramite::PorPagar)),
            'por_autorizar' => Tab::make('Por autorizar')->modifyQueryUsing($etapa(EstadoTramite::PorAutorizar))->badge($cuenta(EstadoTramite::PorAutorizar)),
            'cerrados' => Tab::make('Cerrados')->modifyQueryUsing($etapa(EstadoTramite::Concluido, EstadoTramite::Rechazado, EstadoTramite::Caducado)),
            'todos' => Tab::make('Todos'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nuevo')->label('Nuevo trámite')->icon(Heroicon::OutlinedPlus)
                ->url(TramiteResource::getUrl('create'))
                ->visible(fn () => TramiteResource::canCreate()),
        ];
    }
}
