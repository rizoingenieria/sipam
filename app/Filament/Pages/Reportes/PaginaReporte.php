<?php

namespace App\Filament\Pages\Reportes;

use App\Enums\Permiso;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Base de las pantallas de reportes: permiso, grupo del menú y botón de PDF. */
abstract class PaginaReporte extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Reportes';

    public ?array $filtros = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->puede(Permiso::ReportesVer) ?? false;
    }

    /** Ruta del PDF con los filtros elegidos. */
    abstract protected function urlPdf(): string;

    protected function accionPdf(): Action
    {
        return Action::make('pdf')
            ->label('Descargar PDF')
            ->icon(Heroicon::OutlinedPrinter)
            ->url(fn () => $this->urlPdf(), shouldOpenInNewTab: true);
    }
}
