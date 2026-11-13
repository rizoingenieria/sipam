<?php

namespace App\Filament\Pages\Reportes;

use App\Filament\Support\TablaHtml;
use App\Services\Reportes\GeneradorReportes;
use App\Services\Reportes\Informes;
use App\Support\Formato;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Informe mensual al superior jerárquico (art. 21, frs. IV, XXIII y XXIV): se remite en los primeros cinco días del mes. */
class InformeMensual extends PaginaReporte
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $title = 'Informe mensual';

    protected static ?string $slug = 'reportes/informe-mensual';

    protected static ?int $navigationSort = 20;

    private ?array $datos = null;

    public function getSubheading(): ?string
    {
        return 'Movimientos, trámites, ingresos y espacios disponibles del mes. Se entrega en los primeros cinco días del mes siguiente.';
    }

    public function mount(): void
    {
        $anterior = now()->subMonthNoOverflow();
        $this->form->fill(['anio' => $anterior->year, 'mes' => $anterior->month]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filtros')->components([
            Grid::make(['default' => 2, 'md' => 4])->schema([
                Select::make('mes')->label('Mes')->required()->selectablePlaceholder(false)->live()
                    ->options(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => ucfirst(GeneradorReportes::nombreMes($m))]))
                    ->afterStateUpdated(fn () => $this->datos = null),
                Select::make('anio')->label('Año')->required()->selectablePlaceholder(false)->live()
                    ->options(collect(range(now()->year, now()->year - 6))->mapWithKeys(fn ($a) => [$a => $a]))
                    ->afterStateUpdated(fn () => $this->datos = null),
            ]),
        ]);
    }

    private function datos(): array
    {
        return $this->datos ??= app(Informes::class)->mensual((int) $this->filtros['anio'], (int) $this->filtros['mes']);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            Section::make('Movimientos en los panteones')->schema([Html::make(fn () => TablaHtml::html(
                ['Panteón', 'Inhumaciones', 'Exhumaciones', 'Reinhumaciones', 'Traslados'],
                $this->datos()['movimientos']->map(fn ($m) => [$m->panteon, $m->inhumacion, $m->exhumacion, $m->reinhumacion, $m->traslado]),
                [1, 2, 3, 4], 'No hubo movimientos en el mes.'))]),
            Section::make('Trámites')->schema([Html::make(fn () => TablaHtml::html(
                ['Trámite', 'Recibidos', 'Concluidos', 'Rechazados', 'Caducados', 'En curso al cierre'],
                collect($this->datos()['tramites'])->map(fn ($t) => array_values($t)), [1, 2, 3, 4, 5]))]),
            Section::make('Ingresos')->schema([Html::make(fn () => TablaHtml::html(
                ['Concepto', 'Órdenes', 'Importe'],
                $this->datos()['ingresos']->map(fn ($r) => [$r->concepto, $r->ordenes, Formato::dinero($r->importe)])
                    ->push(['Total', $this->datos()['ingresos']->sum('ordenes'), Formato::dinero($this->datos()['ingresos']->sum('importe'))]),
                [1, 2]))]),
            Section::make('Espacios disponibles hoy')->description('Libres: sin título ni sepultados. Con título y vacíos: sólo los usa su titular.')
                ->schema([Html::make(fn () => TablaHtml::html(
                    ['Panteón', 'Sección', 'Espacios', 'Ocupados', 'Con título, vacíos', 'Libres', 'No disponibles'],
                    $this->datos()['disponibilidad']->map(fn ($d) => [$d->panteon, $d->seccion, $d->total, $d->ocupados, $d->con_titulo_vacios, $d->libres, $d->no_disponibles]),
                    [2, 3, 4, 5, 6], 'Aún no hay espacios en el inventario.'))]),
        ]);
    }

    protected function urlPdf(): string
    {
        return route('filament.panteones.reportes.mensual', ['anio' => $this->filtros['anio'], 'mes' => $this->filtros['mes']]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->accionPdf()];
    }
}
