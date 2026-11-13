<?php

namespace App\Filament\Pages\Reportes;

use App\Filament\Support\TablaHtml;
use App\Services\Reportes\Informes;
use App\Support\Formato;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Informe anual a la Secretaría de Salud (art. 21, fr. XXX): se remite en octubre de cada año. */
class InformeSalud extends PaginaReporte
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $title = 'Informe anual a Salud';

    protected static ?string $slug = 'reportes/informe-salud';

    protected static ?int $navigationSort = 30;

    public function getSubheading(): ?string
    {
        return 'Ubicación, denominación, extensión y capacidad de cada panteón (art. 21, fr. XXX). Se envía a la Secretaría de Salud en octubre.';
    }

    public function mount(): void
    {
        $this->form->fill(['anio' => now()->year]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filtros')->components([
            Grid::make(['default' => 2, 'md' => 4])->schema([
                Select::make('anio')->label('Año')->required()->selectablePlaceholder(false)->live()
                    ->options(collect(range(now()->year, now()->year - 6))->mapWithKeys(fn ($a) => [$a => $a])),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        $faltan = fn () => app(Informes::class)->anualSalud((int) $this->filtros['anio'])['panteones']
            ->filter(fn ($p) => $p->domicilio === null || $p->extension === null || $p->capacidad === null)->pluck('nombre');

        return $schema->components([
            EmbeddedSchema::make('form'),
            Callout::make('Faltan datos de algunos panteones')
                ->description(fn () => 'Completa domicilio, extensión y capacidad de: '.$faltan()->implode(', ').'. Se capturan en Inventario › Panteones.')
                ->warning()
                ->visible(fn () => $faltan()->isNotEmpty()),
            Section::make('Panteones')->schema([Html::make(fn () => TablaHtml::html(
                ['Denominación', 'Ubicación', 'Extensión (m²)', 'Capacidad', 'Espacios registrados', 'Ocupados', 'Libres', 'Inhumaciones', 'Exhumaciones'],
                app(Informes::class)->anualSalud((int) $this->filtros['anio'])['panteones']->map(fn ($p) => [
                    $p->nombre, $p->domicilio ?? 'Sin dato', $p->extension !== null ? Formato::numero($p->extension, 2) : 'Sin dato',
                    $p->capacidad !== null ? Formato::numero($p->capacidad) : 'Sin dato', Formato::numero($p->registrados),
                    Formato::numero($p->ocupados), Formato::numero($p->libres), $p->inhumaciones, $p->exhumaciones,
                ]), [2, 3, 4, 5, 6, 7, 8], 'No hay panteones registrados.'))]),
        ]);
    }

    protected function urlPdf(): string
    {
        return route('filament.panteones.reportes.salud', ['anio' => $this->filtros['anio']]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->accionPdf()];
    }
}
