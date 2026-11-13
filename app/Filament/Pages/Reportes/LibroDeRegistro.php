<?php

namespace App\Filament\Pages\Reportes;

use App\Enums\Permiso;
use App\Enums\TipoMovimiento;
use App\Models\Movimiento;
use App\Models\Panteon;
use App\Services\Reportes\LibroRegistro;
use App\Services\Reportes\Periodo;
use App\Support\Csv;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Libro de inhumaciones, exhumaciones, traslados y reinhumaciones (art. 21, fr. III). */
class LibroDeRegistro extends PaginaReporte implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $title = 'Libro de registro';

    protected static ?string $slug = 'reportes/libro-de-registro';

    protected static ?int $navigationSort = 10;

    public function getSubheading(): ?string
    {
        return 'Inhumaciones, exhumaciones, traslados y reinhumaciones en orden cronológico (art. 21, fr. III). Sale de los movimientos, que no se pueden alterar.';
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
                Select::make('tipo')->label('Movimiento')->options(TipoMovimiento::class)->placeholder('Todos')
                    ->live()->afterStateUpdated(fn () => $this->resetTable()),
                Select::make('panteon')->label('Panteón')->options(fn () => Panteon::orderBy('nombre')->pluck('nombre', 'id'))->placeholder('Todos')
                    ->live()->afterStateUpdated(fn () => $this->resetTable()),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form'), EmbeddedTable::make()]);
    }

    private function periodo(): Periodo
    {
        return Periodo::de($this->filtros['desde'] ?? null, $this->filtros['hasta'] ?? null);
    }

    private function tipo(): ?TipoMovimiento
    {
        $t = $this->filtros['tipo'] ?? null;

        return $t instanceof TipoMovimiento ? $t : TipoMovimiento::tryFrom((string) $t);
    }

    private function panteon(): ?int
    {
        return filled($this->filtros['panteon'] ?? null) ? (int) $this->filtros['panteon'] : null;
    }

    private function verCausa(): bool
    {
        return auth()->user()?->puede(Permiso::InhumadosVerCausa) ?? false;
    }

    public function table(Table $table): Table
    {
        $col = fn (string $clave) => fn (Movimiento $m) => LibroRegistro::renglon($m, $this->verCausa())[$clave];

        return $table
            ->query(fn () => LibroRegistro::consulta($this->periodo(), $this->tipo(), $this->panteon()))
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('fecha_hora')->label('Fecha')->state($col('fecha')),
                TextColumn::make('tipo')->label('Movimiento')->badge(),
                TextColumn::make('nombre')->label('Nombre')->weight('semibold')->wrap()->state($col('nombre'))
                    ->description(fn (Movimiento $m) => collect([$col('sexo')($m), $col('edad')($m), ($a = $col('acta')($m)) ? "acta {$a}" : null])->filter()->implode(' · ')),
                TextColumn::make('lugar')->label('Dónde')->wrap()
                    ->state(fn (Movimiento $m) => collect([($o = $col('origen')($m)) ? "De {$o}" : null, ($d = $col('destino')($m)) ? "a {$d}" : null])->filter()->implode(' ')),
                TextColumn::make('causa')->label('Causa o motivo')->wrap()->placeholder('—')
                    ->state(fn (Movimiento $m) => $col('causa_movimiento')($m) ?? $col('causa_fallecimiento')($m)),
                TextColumn::make('tramite')->label('Trámite')->state($col('tramite'))->placeholder('—'),
            ])
            ->emptyStateHeading('No hay movimientos en ese periodo')
            ->emptyStateDescription('Cambia las fechas o los filtros de arriba.');
    }

    protected function urlPdf(): string
    {
        return route('filament.panteones.reportes.libro', array_filter([
            'desde' => $this->periodo()->desde->toDateString(), 'hasta' => $this->periodo()->hasta->toDateString(),
            'tipo' => $this->tipo()?->value, 'panteon' => $this->panteon(),
        ]));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->accionPdf(),
            Action::make('excel')
                ->label('Descargar Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => Csv::descargar('libro-de-registro-'.$this->periodo()->desde->format('Y-m-d').'.csv',
                    array_values(LibroRegistro::COLUMNAS),
                    LibroRegistro::consulta($this->periodo(), $this->tipo(), $this->panteon())->lazy(500)
                        ->map(fn (Movimiento $m) => array_values(LibroRegistro::renglon($m, $this->verCausa()))))),
        ];
    }
}
