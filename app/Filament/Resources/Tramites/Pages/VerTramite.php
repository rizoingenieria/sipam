<?php

namespace App\Filament\Resources\Tramites\Pages;

use App\Enums\DestinoRestos;
use App\Enums\EstadoOrdenPago;
use App\Enums\EstadoTramite;
use App\Enums\Permiso;
use App\Enums\TipoTramite;
use App\Filament\Resources\Tramites\TramiteResource;
use App\Filament\Support\Avisos;
use App\Filament\Support\ListaMotivos;
use App\Models\ConceptoCobro;
use App\Models\Espacio;
use App\Models\OrdenPago;
use App\Services\Documentos\DocumentosDelTramite;
use App\Services\Tramites\GestorTramites;
use App\Services\Tramites\GuiaTramite;
use App\Services\Tramites\PasoTramite;
use App\Services\Tramites\ValidadorTramite;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

/**
 * Expediente del trámite. Arriba, «Qué sigue»: el paso, lo que falta y el botón que lo da.
 * Abajo, las pestañas para integrar el expediente: documentos, personas, fotos, pagos e historial.
 */
class VerTramite extends ViewRecord
{
    protected static string $resource = TramiteResource::class;

    protected static ?string $breadcrumb = 'Expediente';

    private ?PasoTramite $pasoEnCache = null;

    /** @var ?list<array{etiqueta: string, url: string}> */
    private ?array $documentosDelPaso = null;

    public function getTitle(): string|Htmlable
    {
        return "{$this->record->folio} · {$this->record->tipo->getLabel()}";
    }

    public function getSubheading(): ?string
    {
        return $this->record->espacio->clave_completa.' · solicita '.$this->record->solicitante->nombre_completo;
    }

    #[On('expediente-actualizado')]
    public function refrescarExpediente(): void
    {
        $this->record->refresh();
        $this->pasoEnCache = null;
        $this->documentosDelPaso = null;
    }

    private function paso(): PasoTramite
    {
        return $this->pasoEnCache ??= app(GuiaTramite::class)->paso($this->record);
    }

    private function gestor(): GestorTramites
    {
        return app(GestorTramites::class);
    }

    private function puede(?Permiso $permiso): bool
    {
        return $permiso !== null && (auth()->user()?->puede($permiso) ?? false);
    }

    /** Corre un paso del gestor; si se bloquea, muestra todos los motivos. */
    private function dar(Action $action, callable $paso, string $listo, ?string $detalle = null): void
    {
        try {
            Avisos::intentar($paso, $action);
        } finally {
            // Aunque el paso se bloquee, pudo moverse algo (por ejemplo, pasar a revisión): se vuelve a leer.
            $this->refrescarExpediente();
            $this->dispatch('expediente-actualizado');
        }
        Avisos::listo($listo, $detalle);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(fn () => $this->paso()->titulo)
                ->key('siguiente')
                ->description(fn () => $this->descripcionDelPaso())
                ->icon(fn () => match (true) {
                    $this->record->estado === EstadoTramite::Concluido => Heroicon::OutlinedCheckCircle,
                    $this->record->estado->esTerminal() => Heroicon::OutlinedXCircle,
                    $this->paso()->listo() => Heroicon::OutlinedCheckCircle,
                    default => Heroicon::OutlinedArrowRightCircle,
                })
                ->color(fn () => match (true) {
                    $this->record->estado === EstadoTramite::Concluido => 'success',
                    $this->record->estado->esTerminal() => 'gray',
                    $this->paso()->listo() => 'success',
                    default => 'warning',
                })
                ->footerActions([
                    $this->accionCerrarRevision(),
                    $this->accionRegistrarPublicacion(),
                    $this->accionConcluirPublicacion(),
                    $this->accionRegistrarPago(),
                    $this->accionConcluir(),
                    $this->accionCotejo(),
                    // Lo que hay que imprimir para este paso (responsiva, consentimiento, orden de pago…).
                    ...array_map(fn (int $i) => $this->accionImprimirDelPaso($i), range(0, 3)),
                ])
                ->columnSpanFull(),

            Section::make('Datos del trámite')
                ->collapsible()
                ->schema([Text::make(fn () => $this->datos())])
                ->columnSpanFull(),
        ]);
    }

    private function descripcionDelPaso(): HtmlString
    {
        $paso = $this->paso();
        $html = '<p style="margin:0 0 .75rem">'.e($paso->explicacion).'</p>';

        if (! $paso->terminado) {
            if ($paso->pendientes !== []) {
                // Los documentos que faltan van juntos y cortos: una lista de 15 avisos iguales no se lee.
                [$documentos, $otros] = collect($paso->pendientes)->partition(fn ($m) => $m->queHacer === ValidadorTramite::RECIBIR_DOCUMENTO);
                if ($documentos->isNotEmpty()) {
                    $html .= '<p style="margin:0 0 .35rem;font-weight:700">'.($documentos->count() === 1 ? 'Falta 1 documento' : "Faltan {$documentos->count()} documentos")
                        .' <span style="font-weight:400">(recíbelos en la pestaña «Documentos»):</span></p>'
                        .'<ul style="margin:0 0 .9rem;padding-left:1.2rem;list-style:disc;columns:2;column-gap:2rem">'
                        .$documentos->map(fn ($m) => '<li>'.e(rtrim(preg_replace('/^Falta: /u', '', $m->problema), '.')).'</li>')->implode('')
                        .'</ul>';
                }
                if ($otros->isNotEmpty()) {
                    $html .= '<p style="margin:0 0 .5rem;font-weight:700">'.($otros->count() === 1 ? 'Además, falta:' : 'Además, faltan:').'</p>'
                        .ListaMotivos::html($otros->values()->all())->toHtml();
                }
            } else {
                $html .= '<p style="margin:0;font-weight:700">Todo está completo para este paso.</p>';
            }
            if ($paso->permiso && ! $this->puede($paso->permiso)) {
                $html .= '<p style="margin:.75rem 0 0">Este paso lo da: <strong>'.e(GuiaTramite::quienPuede($paso->permiso)).'</strong>.</p>';
            }
        }

        return new HtmlString($html);
    }

    /** Visible sólo si es el paso que sigue y el usuario puede darlo. */
    private function esElPaso(string $accion): bool
    {
        return $this->paso()->accion === $accion && $this->puede($this->paso()->permiso);
    }

    private function accionCerrarRevision(): Action
    {
        return Action::make('cerrarRevision')
            ->label(fn () => $this->paso()->etiquetaAccion ?? 'Cerrar la revisión')
            ->icon(Heroicon::OutlinedCheck)
            ->requiresConfirmation()
            ->modalHeading('¿Cerrar la revisión?')
            ->modalDescription(fn () => $this->record->por_orden_autoridad
                ? 'El expediente queda fijo y el trámite pasa a autorización.'
                : 'El expediente queda fijo y se emite la orden de pago con las cuotas vigentes del tabulador.')
            ->modalSubmitActionLabel('Cerrar la revisión')
            ->action(function (Action $action): void {
                $this->dar($action, function (): void {
                    if ($this->record->estado === EstadoTramite::Capturado) {
                        $this->gestor()->iniciarRevision($this->record, auth()->user());
                    }
                    $this->gestor()->completarRevision($this->record, auth()->user());
                }, 'Revisión cerrada', $this->record->por_orden_autoridad ? 'El trámite pasó a autorización.' : 'Se emitió la orden de pago. Entrégala al solicitante para que pague en Tesorería.');
            })
            ->button()
            ->size('lg')
            ->disabled(fn () => ! $this->paso()->listo())
            ->tooltip(fn () => $this->paso()->listo() ? null : 'Primero resuelve lo que falta, arriba.')
            ->visible(fn () => $this->esElPaso('cerrarRevision'));
    }

    private function accionRegistrarPublicacion(): Action
    {
        return Action::make('registrarPublicacion')
            ->label('Registrar la publicación')
            ->icon(Heroicon::OutlinedMegaphone)
            ->modalHeading('Registrar la publicación')
            ->modalDescription('Confirma la fecha en que se colocó la notificación en estrados y en el lote (art. 34).')
            ->modalSubmitActionLabel('Registrar')
            ->schema([DatePicker::make('fecha')->label('Fecha de publicación')->required()->default(now())->maxDate(now())])
            ->action(function (array $data, Action $action): void {
                $this->dar($action, function () use ($data): void {
                    if ($this->record->estado === EstadoTramite::Capturado) {
                        $this->gestor()->iniciarRevision($this->record, auth()->user());
                    }
                    $this->gestor()->registrarPublicacion($this->record, auth()->user(), Carbon::parse($data['fecha']));
                }, 'Publicación registrada');
            })
            ->button()
            ->size('lg')
            ->disabled(fn () => ! $this->paso()->listo())
            ->tooltip(fn () => $this->paso()->listo() ? null : 'Primero resuelve lo que falta, arriba.')
            ->visible(fn () => $this->esElPaso('registrarPublicacion'));
    }

    private function accionConcluirPublicacion(): Action
    {
        return Action::make('concluirPublicacion')
            ->label('Terminar la publicación y emitir la orden de pago')
            ->icon(Heroicon::OutlinedCheck)
            ->requiresConfirmation()
            ->modalHeading('¿Terminó la publicación sin oposición?')
            ->modalDescription('Se emite la orden de pago con la cuota vigente del tabulador.')
            ->modalSubmitActionLabel('Sí, emitir la orden de pago')
            ->action(fn (Action $action) => $this->dar($action,
                fn () => $this->gestor()->concluirPublicacion($this->record, auth()->user()), 'Orden de pago emitida'))
            ->button()
            ->size('lg')
            ->disabled(fn () => ! $this->paso()->listo())
            ->tooltip(fn () => $this->paso()->listo() ? null : 'Primero resuelve lo que falta, arriba.')
            ->visible(fn () => $this->esElPaso('concluirPublicacion'));
    }

    private function accionRegistrarPago(): Action
    {
        $pendientes = fn () => $this->record->ordenesPago()->with('concepto')->where('estado', EstadoOrdenPago::Emitida->value)->get();

        return Action::make('registrarPago')
            ->label('Registrar recibo de pago')
            ->icon(Heroicon::OutlinedBanknotes)
            ->modalHeading('Registrar recibo de Tesorería')
            ->modalDescription('Copia los datos del recibo oficial que trae el solicitante.')
            ->modalSubmitActionLabel('Registrar pago')
            ->fillForm(fn () => ['orden_id' => $pendientes()->first()?->id, 'fecha' => now()->toDateString()])
            ->schema([
                Select::make('orden_id')->label('Orden de pago')->required()->selectablePlaceholder(false)
                    ->options(fn () => $pendientes()->mapWithKeys(fn (OrdenPago $o) => [$o->id => "{$o->folio} · {$o->concepto->nombre} · ".Formato::dinero($o->importe)])),
                TextInput::make('recibo_folio')->label('Folio del recibo de Tesorería')->required()->maxLength(50),
                DatePicker::make('fecha')->label('Fecha del recibo')->required()->maxDate(now()),
            ])
            ->action(fn (array $data, Action $action) => $this->dar($action,
                fn () => $this->gestor()->registrarPago(OrdenPago::findOrFail($data['orden_id']), $data['recibo_folio'], Carbon::parse($data['fecha']), auth()->user()),
                'Pago registrado'))
            ->button()
            ->size('lg')
            ->visible(fn () => $this->esElPaso('registrarPago'));
    }

    private function accionConcluir(): Action
    {
        return Action::make('concluir')
            ->label('Autorizar y concluir')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->modalHeading('Autorizar el trámite')
            ->modalDescription(fn () => match ($this->record->tipo) {
                TipoTramite::Adquisicion, TipoTramite::Regularizacion => 'Se emite el título a nombre de los nuevos titulares.',
                TipoTramite::Inhumacion => 'Se registra la inhumación en el libro y en el espacio.',
                TipoTramite::Exhumacion => 'Se registra la exhumación y el destino de los restos.',
                TipoTramite::Construccion => 'Se autoriza el permiso de obra.',
            }.' Esto no se puede deshacer.')
            ->modalSubmitActionLabel('Autorizar')
            ->schema([Textarea::make('resolucion')->label('Resolución')->default('Procedente.')->required()->rows(2)->maxLength(1000)])
            ->action(fn (array $data, Action $action) => $this->dar($action,
                fn () => $this->gestor()->concluir($this->record, auth()->user(), $data['resolucion']), 'Trámite concluido'))
            ->button()
            ->size('lg')
            ->disabled(fn () => ! $this->paso()->listo())
            ->tooltip(fn () => $this->paso()->listo() ? null : 'Primero resuelve lo que falta, arriba.')
            ->visible(fn () => $this->esElPaso('concluir'));
    }

    private function accionCotejo(): Action
    {
        return Action::make('cotejo')
            ->label('Registrar el cotejo')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('gray')
            ->modalHeading('Registrar el cotejo')
            ->modalDescription('Confirma que revisaste en físico el espacio y comparaste los documentos con los registros, con el titular o posesionario presente (art. 21, fr. XXIX).')
            ->modalSubmitActionLabel('Registrar cotejo')
            ->schema([Textarea::make('observaciones')->label('Observaciones')->helperText('Opcional. Lo que encontraste al revisar.')->rows(2)->maxLength(1000)])
            ->action(fn (array $data, Action $action) => $this->dar($action,
                fn () => $this->gestor()->registrarCotejo($this->record, auth()->user(), $data['observaciones'] ?? null), 'Cotejo registrado'))
            ->button()
            ->outlined()
            ->visible(fn () => $this->record->cotejo_en === null
                && in_array($this->record->estado, [EstadoTramite::Capturado, EstadoTramite::EnRevision], true)
                && $this->puede(Permiso::TramitesCapturar));
    }

    /** @return list<array{etiqueta: string, url: string}> */
    private function documentosDelPaso(): array
    {
        return $this->documentosDelPaso ??= DocumentosDelTramite::delPaso($this->record);
    }

    private function accionImprimirDelPaso(int $i): Action
    {
        return Action::make("imprimirPaso{$i}")
            ->label(fn () => $this->documentosDelPaso()[$i]['etiqueta'] ?? '')
            ->url(fn () => $this->documentosDelPaso()[$i]['url'] ?? null, shouldOpenInNewTab: true)
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->button()
            ->outlined()
            ->visible(fn () => isset($this->documentosDelPaso()[$i]));
    }

    protected function getHeaderActions(): array
    {
        $documentos = DocumentosDelTramite::todos($this->record);

        return [
            ActionGroup::make(array_map(fn (array $d, int $i) => Action::make("imprimir{$i}")
                ->label($d['etiqueta'])->icon(Heroicon::OutlinedDocumentText)
                ->url($d['url'], shouldOpenInNewTab: true), $documentos, array_keys($documentos)))
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->button()
                ->visible($documentos !== []),
            Action::make('rechazar')
                ->label('Rechazar')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->outlined()
                ->modalHeading('Rechazar el trámite')
                ->modalDescription('El trámite se cierra y las órdenes sin pagar se cancelan. El solicitante leerá el motivo.')
                ->modalSubmitActionLabel('Rechazar el trámite')
                ->modalCancelActionLabel('No rechazar')
                ->schema([Textarea::make('motivo')->label('Motivo del rechazo')->helperText('Explícalo en palabras sencillas y, si puedes, cita el artículo.')->required()->rows(3)->maxLength(1000)])
                ->action(fn (array $data, Action $action) => $this->dar($action,
                    fn () => $this->gestor()->rechazar($this->record, auth()->user(), $data['motivo']), 'Trámite rechazado'))
                ->visible(fn () => ! $this->record->estado->esTerminal() && $this->puede(Permiso::TramitesRechazar)),
        ];
    }

    private function datos(): HtmlString
    {
        $t = $this->record;
        $filas = [
            'Estado' => $t->estado->getLabel(),
            'Espacio' => $t->espacio->clave_completa,
            'Solicitante' => $t->solicitante->nombre_completo.($t->solicitante->telefono ? " · tel. {$t->solicitante->telefono}" : ''),
            'Capturado' => Formato::fecha($t->created_at).' por '.($t->capturadoPor?->name ?? '—'),
        ];
        if ($t->caduca_en && ! $t->estado->esTerminal()) {
            $filas['Caduca'] = Formato::fecha($t->caduca_en).' (art. 34)';
        }
        if ($t->por_orden_autoridad) {
            $filas['Por orden de autoridad'] = 'Sí: no se cobra (art. 68).';
        }

        $filas += match ($t->tipo) {
            TipoTramite::Adquisicion => ['Tiempo de posesión' => $t->dato('tiempo_posesion'), 'Tipo de título' => $t->dato('regimen_clave') ? ucfirst($t->dato('regimen_clave')) : null],
            TipoTramite::Regularizacion => ['Vía' => $t->dato('via') === 'cesion' ? 'Cesión o donación (art. 27)' : 'Herencia o legado (art. 67)'],
            TipoTramite::Inhumacion => [
                'Finado' => trim($t->dato('finado.nombre').' '.$t->dato('finado.primer_apellido').' '.$t->dato('finado.segundo_apellido')),
                'Defunción' => $t->dato('finado.fecha_defuncion') ? Formato::fecha(Carbon::parse($t->dato('finado.fecha_defuncion'))).' en '.$t->dato('finado.lugar_fallecimiento') : null,
                'Edad al fallecer' => $t->dato('finado.edad_al_fallecer') !== null ? Formato::cantidad((int) $t->dato('finado.edad_al_fallecer'), 'año') : null,
                'Acta de defunción' => $t->dato('finado.numero_acta_defuncion'),
                'Causa' => $t->causaVisiblePara(auth()->user()) ?? 'Reservada (dato personal sensible)',
                'Permiso' => $t->dato('permiso_folio').' del '.Formato::fecha(Carbon::parse($t->dato('permiso_fecha'))),
            ],
            TipoTramite::Exhumacion => [
                'Persona a exhumar' => $t->inhumado?->nombre_completo,
                'Motivo' => $t->motivo_exhumacion?->getLabel(),
                'Destino de los restos' => match (DestinoRestos::tryFrom((string) $t->dato('destino.tipo'))) {
                    DestinoRestos::Traslado => 'Traslado a '.$t->dato('destino.destino_externo').', lo hace '.$t->dato('destino.realizado_por'),
                    DestinoRestos::OtroEspacio => 'Reinhumación en '.Espacio::find($t->dato('destino.espacio_destino_id'))?->clave_completa,
                    DestinoRestos::EnSitio => 'Al pie de la misma fosa',
                    null => null,
                },
                'Plazo' => $t->evaluacion_plazo['explicacion'] ?? null,
                'Permiso' => $t->dato('permiso_folio').' del '.Formato::fecha(Carbon::parse($t->dato('permiso_fecha'))),
            ],
            TipoTramite::Construccion => [
                'Obra' => ConceptoCobro::where('clave', $t->dato('concepto_clave'))->value('nombre'),
                'Qué se hará' => $t->dato('descripcion'),
                'Materiales' => $t->dato('materiales'),
                'Tiempo estimado' => Formato::cantidad((int) $t->dato('tiempo_estimado_dias'), 'día'),
                'Medidas' => collect(['largo_m' => 'largo', 'ancho_m' => 'ancho', 'altura_m' => 'altura', 'altura_total_m' => 'altura total'])
                    ->filter(fn ($n, $k) => $t->dato($k) !== null)->map(fn ($n, $k) => "{$n} ".Formato::numero((float) $t->dato($k), 2).' m')->implode(', ') ?: null,
                'Permiso de Obras Públicas' => $t->dato('permiso_obras_folio'),
                'Permiso del INAH' => $t->dato('permiso_inah_folio'),
            ],
        };

        if ($t->cotejo_en) {
            $filas['Cotejo'] = 'Hecho el '.Formato::fecha($t->cotejo_en).($t->cotejo_observaciones ? ": {$t->cotejo_observaciones}" : '');
        }
        if ($t->pago_derechos_al_corriente) {
            $filas['Pago de derechos'] = 'Al corriente, comprobante '.$t->pago_derechos_folio;
        }
        if ($t->titulo_resultante_id) {
            $filas['Título emitido'] = $t->tituloResultante->folio;
        }

        $html = '<dl style="display:grid;grid-template-columns:max-content 1fr;gap:.5rem 1.5rem;margin:0">';
        foreach (array_filter($filas, fn ($v) => filled($v)) as $k => $v) {
            $html .= '<dt style="font-weight:600">'.e($k).'</dt><dd style="margin:0">'.e($v).'</dd>';
        }

        return new HtmlString($html.'</dl>');
    }
}
