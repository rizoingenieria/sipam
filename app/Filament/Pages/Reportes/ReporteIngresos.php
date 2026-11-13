<?php

namespace App\Filament\Pages\Reportes;

use App\Filament\Support\TablaHtml;
use App\Models\OrdenPago;
use App\Services\Reportes\Ingresos;
use App\Services\Reportes\Periodo;
use App\Support\Csv;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Pagos registrados por periodo, para conciliar con Tesorería (art. 20, fr. XII, inciso d). */
class ReporteIngresos extends PaginaReporte implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $title = 'Ingresos';

    protected static ?string $slug = 'reportes/ingresos';

    protected static ?int $navigationSort = 40;

    public function getSubheading(): ?string
    {
        return 'Órdenes pagadas, por la fecha del recibo de Tesorería. Cada renglón trae el folio del recibo para conciliar.';
    }

    public function mount(): void
    {
        $this->form->fill(['desde' => now()->startOfMonth()->toDateString(), 'hasta' => now()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filtros')->components([
            Grid::make(['default' => 1, 'md' => 4])->schema([
                DatePicker::make('desde')->label('Desde')->live()->afterStateUpdated(fn () => $this->resetTable()),
                DatePicker::make('hasta')->label('Hasta')->live()->afterStateUpdated(fn () => $this->resetTable()),
            ]),
        ]);
    }

    private function periodo(): Periodo
    {
        return Periodo::de($this->filtros['desde'] ?? null, $this->filtros['hasta'] ?? null);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            Section::make('Resumen por concepto')->schema([Html::make(fn () => $this->resumen())]),
            EmbeddedTable::make(),
        ]);
    }

    private function resumen(): HtmlString
    {
        $r = Ingresos::porConcepto($this->periodo());
        if ($r->isEmpty()) {
            return new HtmlString('<p style="margin:0">No hay pagos registrados en el periodo.</p>');
        }
        $html = TablaHtml::html(['Concepto', 'Órdenes', 'Importe'],
            $r->map(fn ($f) => [$f->concepto, $f->ordenes, Formato::dinero($f->importe)])->push(['Total', $r->sum('ordenes'), Formato::dinero($r->sum('importe'))]),
            [1, 2])->toHtml();

        return new HtmlString($html.'<p style="margin:.5rem 0 0;color:var(--sp-ink-soft)">Órdenes emitidas sin pagar: '.Ingresos::pendientes($this->periodo()).'.</p>');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Ingresos::consulta($this->periodo()))
            ->heading('Detalle')
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('pagada_en')->label('Fecha del recibo')->formatStateUsing(fn ($state) => Formato::fecha($state)),
                TextColumn::make('recibo_folio')->label('Recibo')->weight('semibold'),
                TextColumn::make('folio')->label('Orden')->description(fn (OrdenPago $o) => $o->tramite->folio),
                TextColumn::make('tramite.solicitante.nombre_completo')->label('Contribuyente')->wrap(),
                TextColumn::make('concepto.nombre')->label('Concepto')->wrap(),
                TextColumn::make('importe')->label('Importe')->alignEnd()->formatStateUsing(fn ($state) => Formato::dinero($state)),
            ])
            ->emptyStateHeading('No hay pagos en ese periodo');
    }

    protected function urlPdf(): string
    {
        return route('filament.panteones.reportes.ingresos', ['desde' => $this->periodo()->desde->toDateString(), 'hasta' => $this->periodo()->hasta->toDateString()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->accionPdf(),
            Action::make('excel')
                ->label('Descargar Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => Csv::descargar('ingresos-'.$this->periodo()->desde->format('Y-m-d').'.csv',
                    ['Fecha del recibo', 'Recibo', 'Orden', 'Trámite', 'Contribuyente', 'Concepto', 'Importe'],
                    Ingresos::consulta($this->periodo())->lazy(500)->map(fn (OrdenPago $o) => [
                        $o->pagada_en->format('Y-m-d'), $o->recibo_folio, $o->folio, $o->tramite->folio,
                        $o->tramite->solicitante->nombre_completo, $o->concepto->nombre, number_format((float) $o->importe, 2, '.', ''),
                    ]))),
        ];
    }
}
