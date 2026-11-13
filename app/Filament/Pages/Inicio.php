<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BandejaTramites;
use App\Filament\Widgets\ListaArranque;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

class Inicio extends Dashboard
{
    protected static ?string $title = 'Inicio';

    protected static ?string $navigationLabel = 'Inicio';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    public function getSubheading(): ?string
    {
        return 'Lo que requiere tu atención hoy.';
    }

    public function getWidgets(): array
    {
        return [BandejaTramites::class, ListaArranque::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
