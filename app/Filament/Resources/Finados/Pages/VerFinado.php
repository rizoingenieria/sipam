<?php

namespace App\Filament\Resources\Finados\Pages;

use App\Enums\EstadoInhumado;
use App\Enums\MotivoExhumacion;
use App\Enums\Permiso;
use App\Enums\Sexo;
use App\Enums\TipoTramite;
use App\Filament\Resources\Finados\FinadoResource;
use App\Filament\Resources\Tramites\TramiteResource;
use App\Filament\Support\AcervoEnFicha;
use App\Filament\Support\Avisos;
use App\Filament\Support\Ficha;
use App\Models\Movimiento;
use App\Models\Tramite;
use App\Services\Plazos\EvaluadorPlazoExhumacion;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/** Ficha del finado: sus datos, desde cuándo se puede exhumar según el motivo, y su historia. */
class VerFinado extends ViewRecord
{
    protected static string $resource = FinadoResource::class;

    protected static ?string $breadcrumb = 'Ficha';

    public function getTitle(): string|Htmlable
    {
        return $this->record->nombre_completo;
    }

    public function getSubheading(): ?string
    {
        return $this->record->espacio
            ? 'Sepultado en '.$this->record->espacio->clave_completa
            : 'Sus restos ya no están en un espacio del panteón.';
    }

    public function infolist(Schema $schema): Schema
    {
        $i = $this->record;

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->columnSpanFull()->schema([
                Section::make('Datos')->schema([Text::make(fn () => Ficha::html([
                    'Situación' => $i->estado->getLabel(),
                    'Sexo' => $i->sexo?->getLabel(),
                    'Nacimiento' => $i->fecha_nacimiento ? Formato::fecha($i->fecha_nacimiento) : null,
                    'Defunción' => FinadoResource::vida($i),
                    'Lugar' => $i->lugar_fallecimiento,
                    'Acta de defunción' => $i->numero_acta_defuncion,
                    'Causa' => $i->causaVisiblePara(auth()->user()) ?? ($i->causa_fallecimiento ? 'Reservada (dato personal sensible)' : null),
                    'Inhumado' => $i->fecha_inhumacion ? Formato::fecha($i->fecha_inhumacion) : 'Fecha no registrada',
                    'Observaciones' => $i->observaciones,
                ]))]),
                Section::make('¿Desde cuándo se puede exhumar?')
                    ->description('Según el motivo. Lo calculan las reglas de «Plazos para exhumar».')
                    ->visible($i->estado === EstadoInhumado::Inhumado)
                    ->schema([Text::make(fn () => $this->plazos())]),
            ]),
            Section::make('Historia')
                ->description('Inhumaciones, exhumaciones y traslados registrados.')
                ->columnSpanFull()
                ->schema([Text::make(fn () => $this->historia())]),
            AcervoEnFicha::seccion($this->record, 'inhumado'),
        ]);
    }

    private function plazos(): HtmlString
    {
        $evaluador = app(EvaluadorPlazoExhumacion::class);
        $titulo = $this->record->espacio?->tituloVigente()->with('regimen')->first();
        $filas = [];
        foreach ([MotivoExhumacion::Traslado, MotivoExhumacion::ReutilizacionFosa, MotivoExhumacion::Vencimiento] as $motivo) {
            // Por vencimiento, además del plazo sanitario, el título debe haber vencido (art. 41, fr. II).
            if ($motivo === MotivoExhumacion::Vencimiento && $titulo !== null && ! $titulo->estaVencidoA()) {
                $filas[$motivo->getLabel()] = $titulo->regimen->duracion_anios >= 99
                    ? 'No aplica: el título es a perpetuidad'
                    : 'Cuando venza el título, el '.Formato::fecha($titulo->fecha_vencimiento);

                continue;
            }
            $r = $evaluador->evaluar($this->record, $motivo);
            $filas[$motivo->getLabel()] = match (true) {
                $r->permitido => 'Ya se puede',
                $r->fechaPermitida !== null => 'A partir del '.Formato::fecha($r->fechaPermitida),
                default => $r->motivo?->problema ?? 'No se puede',
            };
        }
        $filas[MotivoExhumacion::OrdenAutoridad->getLabel()] = 'En cualquier momento (art. 40)';

        $html = '<ul style="margin:0;padding:0;list-style:none;display:grid;gap:.6rem">';
        foreach ($filas as $motivo => $cuando) {
            $html .= '<li><span style="display:block;color:var(--sp-ink-soft);font-size:.95rem">'.e($motivo).'</span><strong>'.e($cuando).'</strong></li>';
        }

        return new HtmlString($html.'</ul>');
    }

    private function historia(): HtmlString
    {
        $movs = $this->record->movimientos()->with(['espacioOrigen.lote.seccion.panteon', 'espacioDestino.lote.seccion.panteon', 'tramite'])->get();
        if ($movs->isEmpty()) {
            return new HtmlString('<p style="margin:0">No hay movimientos registrados. Si es un registro del sistema anterior, su inhumación no se capturó como trámite.</p>');
        }
        $html = '<ol style="margin:0;padding:0;list-style:none;display:grid;gap:.75rem">';
        foreach ($movs as $m) {
            /** @var Movimiento $m */
            $donde = collect([
                $m->espacioOrigen ? 'de '.$m->espacioOrigen->clave_completa : null,
                $m->espacioDestino ? 'a '.$m->espacioDestino->clave_completa : null,
                $m->destino_externo ? 'a '.$m->destino_externo : null,
            ])->filter()->implode(' ');
            $tramite = $m->tramite
                ? ' · <a href="'.e(TramiteResource::getUrl('view', ['record' => $m->tramite])).'" style="text-decoration:underline">'.e($m->tramite->folio).'</a>'
                : '';
            $html .= '<li style="padding-left:1rem;border-left:3px solid var(--sp-rule)"><strong>'.e($m->tipo->getLabel()).'</strong> · '.e(Formato::fecha($m->fecha_hora))
                .($donde ? ' · '.e($donde) : '').$tramite
                .($m->causa ? '<br><span style="color:var(--sp-ink-soft)">'.e($m->causa).'</span>' : '').'</li>';
        }

        return new HtmlString($html.'</ol>');
    }

    private function exhumacionEnCurso(): ?Tramite
    {
        return Tramite::where('inhumado_id', $this->record->id)->where('tipo', TipoTramite::Exhumacion->value)
            ->whereNotIn('estado', ['concluido', 'rechazado', 'caducado'])->first();
    }

    protected function getHeaderActions(): array
    {
        $i = $this->record;

        return [
            Action::make('exhumacionEnCurso')
                ->label(fn () => 'Exhumación en trámite: '.$this->exhumacionEnCurso()?->folio)
                ->icon(Heroicon::OutlinedFolderOpen)
                ->color('gray')
                ->url(fn () => TramiteResource::getUrl('view', ['record' => $this->exhumacionEnCurso()]))
                ->visible(fn () => $this->exhumacionEnCurso() !== null),
            Action::make('exhumar')
                ->label('Iniciar exhumación')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->url(fn () => TramiteResource::getUrl('create', ['tipo' => TipoTramite::Exhumacion->value, 'inhumado' => $i->id]))
                ->visible(fn () => $i->estado === EstadoInhumado::Inhumado && TramiteResource::canCreate() && $this->exhumacionEnCurso() === null),
            Action::make('corregir')
                ->label('Corregir datos')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->modalHeading('Corregir datos del finado')
                ->modalDescription('Para errores de captura o datos que faltan en registros antiguos. Usa el acta de defunción como fuente.')
                ->modalSubmitActionLabel('Guardar corrección')
                ->fillForm(fn () => $i->only(['nombre', 'primer_apellido', 'segundo_apellido', 'sexo', 'fecha_nacimiento', 'fecha_defuncion', 'edad_al_fallecer', 'numero_acta_defuncion', 'lugar_fallecimiento', 'observaciones']))
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('nombre')->label('Nombre o nombres')->required()->maxLength(100),
                        TextInput::make('primer_apellido')->label('Primer apellido')->maxLength(100),
                        TextInput::make('segundo_apellido')->label('Segundo apellido')->maxLength(100),
                        Select::make('sexo')->label('Sexo')->options(Sexo::class)->required(),
                        DatePicker::make('fecha_nacimiento')->label('Fecha de nacimiento')->maxDate(now()),
                        DatePicker::make('fecha_defuncion')->label('Fecha de defunción')->maxDate(now()),
                        TextInput::make('edad_al_fallecer')->label('Edad al fallecer')->numeric()->integer()->minValue(0)->maxValue(130)->suffix('años')
                            ->helperText('De ella depende el plazo para exhumar.'),
                        TextInput::make('numero_acta_defuncion')->label('Número de acta')->maxLength(50),
                        TextInput::make('lugar_fallecimiento')->label('Lugar de fallecimiento')->maxLength(255),
                    ]),
                    Textarea::make('observaciones')->label('Observaciones')->rows(2)->helperText('Anota de dónde sale la corrección, por ejemplo «según acta 00231».'),
                ])
                ->action(function (array $data): void {
                    $this->record->update($data);
                    Avisos::listo('Datos corregidos');
                })
                ->visible(fn () => auth()->user()?->puede(Permiso::InhumadosCorregir) ?? false),
        ];
    }
}
