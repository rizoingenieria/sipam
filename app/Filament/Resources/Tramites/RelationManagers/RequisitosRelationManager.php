<?php

namespace App\Filament\Resources\Tramites\RelationManagers;

use App\Enums\EstadoRequisitoEntregado;
use App\Enums\Permiso;
use App\Enums\TipoArchivo;
use App\Filament\Resources\Tramites\RelationManagers\Concerns\ParteDelExpediente;
use App\Models\TramiteRequisito;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Lista de cotejo: cada documento que pide el trámite y si ya se recibió. */
class RequisitosRelationManager extends RelationManager
{
    use ParteDelExpediente;

    protected static string $relationship = 'requisitos';

    protected static ?string $title = 'Documentos';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static bool $isLazy = false;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $pendientes = $ownerRecord->requisitos()->where('estado', EstadoRequisitoEntregado::Pendiente->value)->count();

        return $pendientes > 0 ? (string) $pendientes : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return 'Documentos pendientes';
    }

    private static function nombre(TramiteRequisito $f): string
    {
        $mismos = $f->tramite->requisitos->where('requisito_id', $f->requisito_id)->count();

        return $f->requisito->documento.($mismos > 1 ? " ({$f->ordinal} de {$mismos})" : '');
    }

    private static function detalle(TramiteRequisito $f): ?string
    {
        return collect([
            $f->fecha_expedicion ? 'Expedido el '.Formato::fecha($f->fecha_expedicion) : null,
            $f->folio_documento ? "Folio {$f->folio_documento}" : null,
            $f->observaciones,
            $f->revisado_en && $f->estado !== EstadoRequisitoEntregado::Pendiente ? 'Registrado por '.($f->revisadoPor?->name ?? '—').' el '.Formato::fecha($f->revisado_en) : null,
        ])->filter()->implode(' · ') ?: null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['requisito', 'revisadoPor', 'tramite.requisitos']))
            ->paginated(false)
            ->description('Marca cada documento conforme lo recibes. Los que firma el ayuntamiento se suben ya firmados.')
            ->columns([
                TextColumn::make('estado')->label('')->badge()->width('8rem'),
                TextColumn::make('requisito.documento')->label('Documento')->weight('semibold')->wrap()
                    ->state(fn (TramiteRequisito $f) => self::nombre($f))
                    ->description(fn (TramiteRequisito $f) => collect([
                        $f->requisito->emitido_por_sistema ? 'Lo firma el ayuntamiento' : 'Lo presenta: '.mb_strtolower($f->requisito->de_quien->getLabel()),
                        $f->requisito->presentacion,
                        $f->requisito->obligatorio ? null : 'opcional',
                        $f->requisito->grupo_alternativa ? 'se puede sustituir por otro de la lista' : null,
                    ])->filter()->implode(' · ')),
                TextColumn::make('detalle')->label('Datos de lo recibido')->wrap()->state(fn (TramiteRequisito $f) => self::detalle($f))->placeholder('—'),
            ])
            ->emptyStateHeading('Este trámite no pide documentos')
            ->recordActions([
                Action::make('recibir')
                    ->label('Recibir')
                    ->icon(Heroicon::OutlinedCheck)
                    ->button()
                    ->modalHeading(fn (TramiteRequisito $record) => 'Recibir: '.$record->requisito->documento)
                    ->modalDescription('Revisa que el documento sea original o copia legible y que corresponda a la persona del trámite.')
                    ->modalSubmitActionLabel('Marcar como recibido')
                    ->schema(fn (TramiteRequisito $record) => array_values(array_filter([
                        $record->requisito->vigencia_maxima_dias ? DatePicker::make('fecha_expedicion')->label('Fecha en que se expidió')->required()->maxDate(now())
                            ->helperText('Debe tener menos de '.Formato::cantidad($record->requisito->vigencia_maxima_dias, 'día').' de antigüedad.') : null,
                        TextInput::make('folio')
                            ->label($record->requisito->activa_pago_derechos ? 'Folio del comprobante de Tesorería' : 'Folio o número del documento')
                            ->required($record->requisito->activa_pago_derechos)
                            ->helperText($record->requisito->activa_pago_derechos ? 'Con este dato se marca que la persona está al corriente en sus pagos.' : 'Opcional.')
                            ->maxLength(100),
                        FileUpload::make('escaneo')->label('Foto o escaneo del documento')->helperText('Opcional. Puedes tomarle foto con el celular.')
                            ->storeFiles(false)->acceptedFileTypes(TipoArchivo::DocumentoEntregado->tiposPermitidos()),
                    ])))
                    ->action(fn (TramiteRequisito $record, array $data, Action $action) => $this->paso($action, fn () => DB::transaction(function () use ($record, $data): void {
                        $this->gestor()->entregarRequisito($record, auth()->user(),
                            filled($data['fecha_expedicion'] ?? null) ? Carbon::parse($data['fecha_expedicion']) : null,
                            $data['folio'] ?? null);
                        if (! empty($data['escaneo'])) {
                            $this->gestor()->adjuntarArchivo($record->tramite, $data['escaneo'], TipoArchivo::DocumentoEntregado, auth()->user(), $record, $record->requisito->documento);
                        }
                    }), 'Documento recibido'))
                    ->visible(fn (TramiteRequisito $record) => $record->estado === EstadoRequisitoEntregado::Pendiente
                        && ! $record->requisito->emitido_por_sistema && $this->editable() && $this->puede(Permiso::TramitesCapturar)),

                Action::make('imprimirParaFirmar')
                    ->label('Imprimir')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (TramiteRequisito $record) => route('filament.panteones.documentos.para-firmar', $record), shouldOpenInNewTab: true)
                    ->visible(fn (TramiteRequisito $record) => $record->requisito->emitido_por_sistema),

                Action::make('subirFirmado')
                    ->label('Subir firmado')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->button()
                    ->modalHeading(fn (TramiteRequisito $record) => 'Subir firmado: '.$record->requisito->documento)
                    ->modalDescription('Imprímelo, recaba la firma y sube el escaneo o una foto clara del documento firmado.')
                    ->modalSubmitActionLabel('Subir')
                    ->schema([
                        FileUpload::make('archivo')->label('Documento firmado')->required()->storeFiles(false)
                            ->acceptedFileTypes(TipoArchivo::DocumentoEmitido->tiposPermitidos()),
                    ])
                    ->action(fn (TramiteRequisito $record, array $data, Action $action) => $this->paso($action,
                        fn () => $this->gestor()->emitirDocumento($record, auth()->user(), $data['archivo']), 'Documento firmado guardado'))
                    ->visible(fn (TramiteRequisito $record) => $record->estado === EstadoRequisitoEntregado::Pendiente
                        && $record->requisito->emitido_por_sistema && $this->abierto() && $this->puede(Permiso::TramitesEmitirDocumentos)),

                ActionGroup::make([
                    Action::make('noAplica')
                        ->label('No aplica')
                        ->icon(Heroicon::OutlinedMinusCircle)
                        ->modalHeading(fn (TramiteRequisito $record) => 'No aplica: '.$record->requisito->documento)
                        ->modalSubmitActionLabel('Marcar como «no aplica»')
                        ->schema([Textarea::make('motivo')->label('¿Por qué no aplica?')->required()->rows(2)->maxLength(255)])
                        ->action(fn (TramiteRequisito $record, array $data, Action $action) => $this->paso($action,
                            fn () => $this->gestor()->marcarNoAplica($record, auth()->user(), $data['motivo']), 'Marcado como «no aplica»'))
                        ->visible(fn (TramiteRequisito $record) => $record->estado === EstadoRequisitoEntregado::Pendiente
                            && (! $record->requisito->obligatorio || $record->requisito->grupo_alternativa !== null)
                            && $this->editable() && $this->puede(Permiso::TramitesCapturar)),
                    Action::make('devolver')
                        ->label('Corregir: no se recibió')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->requiresConfirmation()
                        ->modalHeading('¿Regresarlo a pendiente?')
                        ->modalDescription('Úsalo si lo marcaste por error. Los datos capturados de este documento se borran.')
                        ->modalSubmitActionLabel('Regresar a pendiente')
                        ->action(fn (TramiteRequisito $record, Action $action) => $this->paso($action,
                            fn () => $this->gestor()->devolverRequisito($record, auth()->user()), 'El documento quedó pendiente'))
                        ->visible(fn (TramiteRequisito $record) => $record->estado !== EstadoRequisitoEntregado::Pendiente
                            && ! $record->requisito->emitido_por_sistema && $this->editable() && $this->puede(Permiso::TramitesCapturar)),
                ])->label('Más')->icon(Heroicon::OutlinedEllipsisHorizontalCircle)->color('gray')->link(),
            ]);
    }
}
