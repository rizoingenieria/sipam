<?php

namespace App\Filament\Resources\Tramites\RelationManagers;

use App\Enums\EstadoOrdenPago;
use App\Enums\EstadoTramite;
use App\Enums\Permiso;
use App\Filament\Resources\Tramites\RelationManagers\Concerns\ParteDelExpediente;
use App\Models\OrdenPago;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Órdenes de pago del trámite: el importe queda fijo al emitirse; aquí se registra el recibo de Tesorería. */
class OrdenesRelationManager extends RelationManager
{
    use ParteDelExpediente;

    protected static string $relationship = 'ordenesPago';

    protected static ?string $title = 'Pagos';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedBanknotes;

    protected static bool $isLazy = false;

    public static function accionRegistrarPago(): Action
    {
        return Action::make('registrarPago')
            ->label('Registrar recibo')
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->button()
            ->modalHeading(fn (OrdenPago $record) => "Recibo de la orden {$record->folio}")
            ->modalDescription(fn (OrdenPago $record) => "{$record->concepto->nombre}: ".Formato::dinero($record->importe).'. Copia los datos del recibo oficial de Tesorería.')
            ->modalSubmitActionLabel('Registrar pago')
            ->schema([
                TextInput::make('recibo_folio')->label('Folio del recibo de Tesorería')->required()->maxLength(50),
                DatePicker::make('fecha')->label('Fecha del recibo')->required()->default(now())->maxDate(now()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('concepto'))
            ->paginated(false)
            ->columns([
                TextColumn::make('folio')->label('Orden')->weight('semibold')->description(fn (OrdenPago $o) => $o->concepto->nombre),
                TextColumn::make('importe')->label('Importe')->alignEnd()->formatStateUsing(fn ($state) => Formato::dinero($state)),
                TextColumn::make('estado')->label('Estado')->badge(),
                TextColumn::make('recibo_folio')->label('Recibo')->placeholder('—')
                    ->description(fn (OrdenPago $o) => $o->pagada_en ? 'Del '.Formato::fecha($o->pagada_en) : null),
            ])
            ->emptyStateHeading('Aún no hay órdenes de pago')
            ->emptyStateDescription('Se emiten solas al cerrar la revisión del expediente, con las cuotas del tabulador.')
            ->recordActions([
                Action::make('imprimir')->label('Imprimir')->icon(Heroicon::OutlinedPrinter)->color('gray')
                    ->url(fn (OrdenPago $record) => route('filament.panteones.documentos.orden', $record), shouldOpenInNewTab: true),
                self::accionRegistrarPago()
                    ->action(fn (OrdenPago $record, array $data, Action $action) => $this->paso($action,
                        fn () => $this->gestor()->registrarPago($record, $data['recibo_folio'], Carbon::parse($data['fecha']), auth()->user()), 'Pago registrado'))
                    ->visible(fn (OrdenPago $record) => $record->estado === EstadoOrdenPago::Emitida
                        && $this->getOwnerRecord()->estado === EstadoTramite::PorPagar && $this->puede(Permiso::TramitesRegistrarPagos)),
            ]);
    }
}
