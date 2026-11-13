<?php

namespace App\Filament\Resources\Bitacora\Pages;

use App\Filament\Resources\Bitacora\BitacoraResource;
use App\Models\Bitacora;
use App\Support\Csv;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBitacora extends ListRecords
{
    protected static string $resource = BitacoraResource::class;

    public function getSubheading(): ?string
    {
        return 'Cada alta, cambio y baja, y cada entrada al sistema. No se puede modificar ni borrar.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('descargar')
                ->label('Descargar Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => Csv::descargar('bitacora-'.now()->format('Y-m-d').'.csv',
                    ['Fecha y hora', 'Usuario', 'IP', 'Qué pasó', 'Registro', 'Cambios'],
                    $this->getFilteredSortedTableQuery()->with('usuario')->lazy(500)->map(fn (Bitacora $b) => [
                        $b->created_at->format('Y-m-d H:i:s'), $b->usuario?->name ?? 'El sistema', $b->ip, $b->evento->getLabel(), $b->descripcion,
                        $b->cambios ? json_encode($b->cambios, JSON_UNESCAPED_UNICODE) : '',
                    ]))),
        ];
    }
}
