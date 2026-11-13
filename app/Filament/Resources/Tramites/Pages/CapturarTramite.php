<?php

namespace App\Filament\Resources\Tramites\Pages;

use App\Enums\DestinoRestos;
use App\Enums\EstadoInhumado;
use App\Enums\MotivoExhumacion;
use App\Enums\Sexo;
use App\Enums\TipoTramite;
use App\Filament\Resources\Tramites\TramiteResource;
use App\Filament\Support\Avisos;
use App\Filament\Support\CamposEspacio;
use App\Filament\Support\CamposPersona;
use App\Filament\Support\ListaMotivos;
use App\Models\ConceptoCobro;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Persona;
use App\Models\Regimen;
use App\Models\Tramite;
use App\Services\Plazos\EvaluadorPlazoExhumacion;
use App\Services\Tramites\GestorTramites;
use App\Services\Tramites\ValidadorTramite;
use App\Support\AccionBloqueada;
use App\Support\Formato;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Captura guiada: un paso por pregunta (qué trámite, dónde, quién lo pide, datos, confirmar).
 * Antes de avanzar revisa los candados del espacio, para que nadie llene todo el formulario
 * de un trámite que no procede. Al guardar, el GestorTramites vuelve a validar todo.
 */
class CapturarTramite extends Page
{
    protected static string $resource = TramiteResource::class;

    protected static ?string $title = 'Nuevo trámite';

    protected static ?string $breadcrumb = 'Nuevo';

    public ?array $data = [];

    public int $pasoInicial = 1;

    public const DESCRIPCIONES = [
        'adquisicion' => 'Primer título de un espacio que la familia ya usa y que no tiene título a nombre de nadie.',
        'regularizacion' => 'El espacio ya tiene título y pasa a otra persona: por cesión o donación, o por herencia.',
        'inhumacion' => 'Sepultar a una persona en una fosa, cripta o gaveta.',
        'exhumacion' => 'Sacar los restos de una persona sepultada: para trasladarlos, para reutilizar la fosa o por orden de autoridad.',
        'construccion' => 'Poner lápida, monumento o capilla, o reparar o retirar una obra.',
    ];

    public function mount(): void
    {
        abort_unless(TramiteResource::canCreate(), 403);

        // Atajos desde un título o un finado: llegan con el trámite y el espacio ya elegidos.
        $tipo = TipoTramite::tryFrom((string) request()->query('tipo'));
        $espacio = Espacio::find(request()->query('espacio'));
        $inhumado = Inhumado::find(request()->query('inhumado'));

        $this->form->fill(array_filter([
            'tipo' => $tipo?->value,
            'espacio_id' => $espacio?->id ?? $inhumado?->espacio_id,
            'inhumado_id' => $inhumado?->id,
        ]));
        $this->pasoInicial = $tipo && ($espacio || $inhumado) ? 2 : 1;
    }

    public function getSubheading(): ?string
    {
        return 'Responde cada paso. Si algo impide el trámite, el sistema te avisa antes de seguir.';
    }

    /** La pregunta de cada paso, grande, arriba: el encabezado del asistente sólo dice el nombre del paso. */
    private static function pregunta(string $texto): Text
    {
        return Text::make(new HtmlString('<span style="font-size:1.35rem;font-weight:700;color:var(--sp-ink)">'.e($texto).'</span>'));
    }

    public static function tipo(Get $get): ?TipoTramite
    {
        $v = $get('tipo');

        return $v instanceof TipoTramite ? $v : TipoTramite::tryFrom((string) $v);
    }

    private static function es(TipoTramite ...$tipos): \Closure
    {
        return fn (Get $get): bool => in_array(self::tipo($get), $tipos, true);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Wizard::make([
                Step::make('Trámite')
                    ->icon(Heroicon::OutlinedQueueList)
                    ->schema([
                        self::pregunta('¿Qué necesita la persona?'),
                        Radio::make('tipo')
                            ->hiddenLabel()
                            ->options(collect(TipoTramite::cases())->mapWithKeys(fn (TipoTramite $t) => [$t->value => $t->getLabel()])->all())
                            ->descriptions(self::DESCRIPCIONES)
                            ->required()
                            ->live()
                            ->validationMessages(['required' => 'Elige el trámite que se va a hacer.']),
                    ]),

                Step::make('Espacio')
                    ->icon(Heroicon::OutlinedMapPin)
                    ->schema([
                        self::pregunta('¿En qué fosa, nicho o cripta?'),
                        CamposEspacio::select('espacio_id', 'Espacio')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('inhumado_id', null))
                            ->validationMessages(['required' => 'Busca y elige el espacio.']),
                        Section::make('Datos del espacio')
                            ->compact()
                            ->schema([Text::make(fn (Get $get) => CamposEspacio::ficha(CamposEspacio::cargar($get('espacio_id'))))]),

                        Grid::make(2)->visible(self::es(TipoTramite::Exhumacion))->schema([
                            Select::make('inhumado_id')->label('Persona a exhumar')
                                ->options(fn (Get $get) => Inhumado::where('espacio_id', $get('espacio_id'))->where('estado', EstadoInhumado::Inhumado->value)
                                    ->orderByDesc('fecha_inhumacion')->pluck('nombre_completo', 'id'))
                                ->required()->live()
                                ->helperText(fn (Get $get) => blank($get('espacio_id')) ? 'Primero elige el espacio.' : null),
                            Radio::make('motivo_exhumacion')->label('Motivo')
                                ->options(MotivoExhumacion::class)->required()->live(),
                        ]),
                        Text::make(fn (Get $get) => $this->plazoEnPalabras($get))
                            ->visible(fn (Get $get) => self::tipo($get) === TipoTramite::Exhumacion && filled($get('inhumado_id')) && filled($get('motivo_exhumacion'))),

                        Toggle::make('por_orden_autoridad')->label('Lo ordena una autoridad (fiscalía, servicio médico forense o juez)')
                            ->helperText('No se pide autorizante ni se cobra (arts. 46 y 68).')
                            ->visible(self::es(TipoTramite::Inhumacion)),
                    ])
                    ->afterValidation(fn (Get $get) => $this->revisarCandados($get)),

                Step::make('Solicitante')
                    ->icon(Heroicon::OutlinedUser)
                    ->schema([
                        self::pregunta('¿Quién hace el trámite?'),
                        CamposPersona::select('solicitante_id', 'Persona que solicita el trámite')
                            ->helperText('Búscala por su nombre o CURP. Si no aparece, regístrala con el botón «+».')
                            ->required()
                            ->validationMessages(['required' => 'Elige o registra a la persona que hace el trámite.']),
                    ]),

                Step::make('Datos')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([self::pregunta('Datos que pide este trámite'), ...$this->camposDeDatos()])
                    ->afterValidation(fn (Get $get) => $this->revisarCandados($get)),

                Step::make('Confirmar')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->schema([
                        self::pregunta('Revisa que todo esté bien'),
                        Text::make(fn (Get $get) => $this->resumen($get)),
                        Callout::make('Al guardar se asigna el folio')
                            ->description('Después recibirás los documentos en el expediente del trámite.')
                            ->info(),
                    ]),
            ])
                ->startOnStep(fn () => $this->pasoInicial)
                ->submitAction(new HtmlString(Blade::render('<x-filament::button type="submit" size="lg">Guardar el trámite</x-filament::button>'))),
        ]);
    }

    /** @return array<Component> */
    private function camposDeDatos(): array
    {
        return [
            // Adquisición (art. 33)
            Grid::make(2)->visible(self::es(TipoTramite::Adquisicion))->schema([
                TextInput::make('datos.tiempo_posesion')->label('¿Desde cuándo usa la familia el espacio?')
                    ->helperText('Como lo dice el escrito de solicitud, por ejemplo «desde 1998» o «25 años».')->required()->maxLength(100),
                Select::make('datos.regimen_clave')->label('Tipo de título')
                    ->options(fn () => Regimen::abiertas()->orderBy('nombre')->pluck('nombre', 'clave'))
                    ->default('perpetuidad')->selectablePlaceholder(false),
            ]),

            // Regularización (arts. 27 y 67)
            Radio::make('datos.via')->label('¿Cómo pasa el espacio a la otra persona?')
                ->options(['cesion' => 'Cesión o donación (el titular lo cede en vida)', 'herencia' => 'Herencia o legado (el titular falleció)'])
                ->required()->visible(self::es(TipoTramite::Regularizacion)),

            // Inhumación (art. 21, fr. III, inciso a)
            Fieldset::make('Datos del finado, como vienen en el acta de defunción')
                ->visible(self::es(TipoTramite::Inhumacion))
                ->columns(3)
                ->schema([
                    Toggle::make('datos.finado.es_desconocido')->label('Persona desconocida (sin identificar)')->live()->columnSpanFull(),
                    TextInput::make('datos.finado.nombre')->label('Nombre o nombres')->maxLength(100)
                        ->required(fn (Get $get) => ! $get('datos.finado.es_desconocido')),
                    TextInput::make('datos.finado.primer_apellido')->label('Primer apellido')->maxLength(100),
                    TextInput::make('datos.finado.segundo_apellido')->label('Segundo apellido')->maxLength(100),
                    Select::make('datos.finado.sexo')->label('Sexo')->options(Sexo::class)->required(),
                    DatePicker::make('datos.finado.fecha_defuncion')->label('Fecha de defunción')->required()->maxDate(now())->live(),
                    DatePicker::make('datos.finado.fecha_nacimiento')->label('Fecha de nacimiento')->maxDate(now())->live()
                        ->helperText('Si no la sabes, captura la edad.'),
                    TextInput::make('datos.finado.edad_al_fallecer')->label('Edad al fallecer')->numeric()->integer()->minValue(0)->maxValue(130)->suffix('años')
                        ->required(fn (Get $get) => blank($get('datos.finado.fecha_nacimiento')))
                        ->helperText('De ella depende el plazo para exhumar.'),
                    TextInput::make('datos.finado.numero_acta_defuncion')->label('Número de acta de defunción')->required()->maxLength(50),
                    TextInput::make('datos.finado.lugar_fallecimiento')->label('Lugar de fallecimiento')->required()->maxLength(255),
                    Textarea::make('causa_fallecimiento')->label('Causa de fallecimiento')->required()->maxLength(500)->rows(2)->columnSpanFull()
                        ->helperText('Dato personal sensible: sólo la ve quien tiene permiso.'),
                ]),

            // Exhumación: destino de los restos (art. 21, fr. III, incisos b y c)
            Fieldset::make('A dónde van los restos')
                ->visible(self::es(TipoTramite::Exhumacion))
                ->columns(2)
                ->schema([
                    Radio::make('datos.destino.tipo')->hiddenLabel()->options(DestinoRestos::class)->required()->live()->columnSpanFull(),
                    TextInput::make('datos.destino.destino_externo')->label('Lugar a donde se trasladan')->maxLength(255)
                        ->visible(fn (Get $get) => $this->valor($get('datos.destino.tipo')) === DestinoRestos::Traslado->value)->required(),
                    TextInput::make('datos.destino.realizado_por')->label('Quién hace el traslado')->helperText('Funeraria o persona responsable.')->maxLength(150)
                        ->visible(fn (Get $get) => $this->valor($get('datos.destino.tipo')) === DestinoRestos::Traslado->value)->required(),
                    CamposEspacio::select('datos.destino.espacio_destino_id', 'Espacio a donde irán los restos')
                        ->visible(fn (Get $get) => $this->valor($get('datos.destino.tipo')) === DestinoRestos::OtroEspacio->value)->required()->columnSpanFull(),
                ]),

            // Inhumación y exhumación: permiso sanitario
            Grid::make(2)->visible(self::es(TipoTramite::Inhumacion, TipoTramite::Exhumacion))->schema([
                TextInput::make('datos.permiso_folio')->label('Folio del permiso del Registro Civil o de Salud')->required()->maxLength(50),
                DatePicker::make('datos.permiso_fecha')->label('Fecha del permiso')->required()->maxDate(now()),
            ]),

            // Construcción (arts. 52, 56 y 89)
            Grid::make(2)->visible(self::es(TipoTramite::Construccion))->schema([
                Select::make('datos.concepto_clave')->label('Tipo de obra')->required()
                    ->options(fn () => ConceptoCobro::where('categoria', 'obra')->where('activo', true)->orderBy('orden')->pluck('nombre', 'clave')),
                TextInput::make('datos.tiempo_estimado_dias')->label('Tiempo estimado')->numeric()->integer()->minValue(1)->maxValue(365)->suffix('días')->required(),
                Textarea::make('datos.descripcion')->label('Qué se va a hacer')->required()->maxLength(500)->rows(2)->columnSpanFull(),
                Textarea::make('datos.materiales')->label('Materiales')->required()->maxLength(500)->rows(2)->columnSpanFull(),
                Fieldset::make('Medidas de la obra (del croquis)')->columns(4)->columnSpanFull()->schema([
                    TextInput::make('datos.largo_m')->label('Largo')->numeric()->minValue(0.01)->suffix('m'),
                    TextInput::make('datos.ancho_m')->label('Ancho')->numeric()->minValue(0.01)->suffix('m'),
                    TextInput::make('datos.altura_m')->label('Altura')->numeric()->minValue(0.01)->suffix('m'),
                    TextInput::make('datos.altura_total_m')->label('Altura con cruz o estatua')->numeric()->minValue(0.01)->suffix('m'),
                ]),
                TextInput::make('datos.permiso_obras_folio')->label('Folio del permiso de Obras Públicas')->required()->maxLength(50),
                TextInput::make('datos.permiso_inah_folio')->label('Folio del permiso del INAH')->maxLength(50)
                    ->helperText('Sólo si el espacio es monumento histórico.'),
            ]),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('guardar'),
        ]);
    }

    /** Las opciones de enums pueden llegar como enum o como texto. */
    private function valor(mixed $v): ?string
    {
        return $v instanceof \BackedEnum ? (string) $v->value : (filled($v) ? (string) $v : null);
    }

    /** Trámite de prueba con lo capturado hasta ahora; no se guarda. */
    private function borrador(Get $get): ?Tramite
    {
        $tipo = self::tipo($get);
        if ($tipo === null || blank($get('espacio_id'))) {
            return null;
        }
        // Una exhumación sin persona o sin motivo todavía no se puede revisar; el paso los exige.
        if ($tipo === TipoTramite::Exhumacion && (blank($get('inhumado_id')) || blank($get('motivo_exhumacion')))) {
            return null;
        }

        return new Tramite([
            'tipo' => $tipo,
            'espacio_id' => (int) $get('espacio_id'),
            'inhumado_id' => $get('inhumado_id') ?: null,
            'motivo_exhumacion' => $this->valor($get('motivo_exhumacion')),
            'por_orden_autoridad' => $tipo === TipoTramite::Exhumacion
                ? $this->valor($get('motivo_exhumacion')) === MotivoExhumacion::OrdenAutoridad->value
                : (bool) $get('por_orden_autoridad'),
            'datos' => $this->limpiar($get('datos') ?? []),
        ]);
    }

    private function limpiar(array $datos): array
    {
        array_walk_recursive($datos, function (&$v): void {
            $v = $v instanceof \BackedEnum ? $v->value : $v;
        });

        return $datos;
    }

    /** Detiene el avance si el espacio no admite el trámite, con todos los motivos de una vez. */
    private function revisarCandados(Get $get): void
    {
        $t = $this->borrador($get);
        if ($t === null) {
            return;
        }
        $motivos = app(ValidadorTramite::class)->candados($t);
        if ($motivos !== []) {
            Avisos::bloqueado(AccionBloqueada::por(...$motivos));

            throw new Halt;
        }
    }

    private function plazoEnPalabras(Get $get): HtmlString
    {
        $inhumado = Inhumado::find($get('inhumado_id'));
        $motivo = MotivoExhumacion::tryFrom((string) $this->valor($get('motivo_exhumacion')));
        if (! $inhumado || ! $motivo) {
            return new HtmlString('');
        }
        $r = app(EvaluadorPlazoExhumacion::class)->evaluar($inhumado, $motivo);
        $color = $r->permitido ? 'var(--sp-guide)' : 'var(--sp-alert)';

        return new HtmlString('<p style="margin:0;padding:.75rem 1rem;border-left:3px solid '.$color.';background:#fff">'
            .'<strong>'.($r->permitido ? 'Se puede exhumar.' : 'Todavía no se puede exhumar.').'</strong> '.e($r->explicacion).'</p>');
    }

    private function resumen(Get $get): HtmlString
    {
        $tipo = self::tipo($get);
        $espacio = CamposEspacio::cargar($get('espacio_id'));
        $solicitante = Persona::find($get('solicitante_id'));
        $filas = [
            'Trámite' => $tipo?->getLabel(),
            'Espacio' => $espacio ? CamposEspacio::etiqueta($espacio) : null,
            'Solicitante' => $solicitante?->nombre_completo,
        ];
        if ($tipo === TipoTramite::Exhumacion) {
            $filas['Persona a exhumar'] = Inhumado::find($get('inhumado_id'))?->nombre_completo;
            $filas['Motivo'] = MotivoExhumacion::tryFrom((string) $this->valor($get('motivo_exhumacion')))?->getLabel();
            $filas['Destino'] = DestinoRestos::tryFrom((string) $this->valor($get('datos.destino.tipo')))?->getLabel();
        }
        if ($tipo === TipoTramite::Inhumacion) {
            $f = $get('datos.finado') ?? [];
            $filas['Finado'] = trim(($f['nombre'] ?? '').' '.($f['primer_apellido'] ?? '').' '.($f['segundo_apellido'] ?? '')) ?: 'Persona desconocida';
            $filas['Fecha de defunción'] = filled($f['fecha_defuncion'] ?? null) ? Formato::fecha(Carbon::parse($f['fecha_defuncion'])) : null;
            $filas['Por orden de autoridad'] = $get('por_orden_autoridad') ? 'Sí, no se cobra' : 'No';
        }
        if ($tipo === TipoTramite::Regularizacion) {
            $filas['Vía'] = ['cesion' => 'Cesión o donación', 'herencia' => 'Herencia o legado'][$this->valor($get('datos.via'))] ?? null;
        }
        if ($tipo === TipoTramite::Construccion) {
            $filas['Obra'] = ConceptoCobro::where('clave', $this->valor($get('datos.concepto_clave')))->value('nombre');
        }

        $html = '<dl style="display:grid;grid-template-columns:max-content 1fr;gap:.5rem 1.5rem;margin:0;font-size:1.05rem">';
        foreach ($filas as $k => $v) {
            $html .= '<dt style="font-weight:600">'.e($k).'</dt><dd style="margin:0">'.e($v ?? '—').'</dd>';
        }
        $html .= '</dl>';

        $t = $this->borrador($get);
        $motivos = $t ? app(ValidadorTramite::class)->candados($t) : [];
        if ($motivos !== []) {
            $html .= '<div style="margin-top:1.25rem"><p style="font-weight:700;color:var(--sp-alert);margin:0 0 .5rem">Hay algo que impide guardar:</p>'.ListaMotivos::html($motivos)->toHtml().'</div>';
        }

        return new HtmlString($html);
    }

    public function guardar(): void
    {
        $d = $this->form->getState();
        $tipo = $d['tipo'] instanceof TipoTramite ? $d['tipo'] : TipoTramite::from($d['tipo']);
        $datos = $this->limpiar($d['datos'] ?? []);
        if ($tipo === TipoTramite::Inhumacion && ! empty($datos['finado']['es_desconocido']) && blank($datos['finado']['nombre'] ?? null)) {
            $datos['finado']['nombre'] = 'Persona desconocida';
        }
        $motivo = MotivoExhumacion::tryFrom((string) $this->valor($d['motivo_exhumacion'] ?? null));

        try {
            $tramite = app(GestorTramites::class)->capturar(
                $tipo,
                Espacio::findOrFail($d['espacio_id']),
                Persona::findOrFail($d['solicitante_id']),
                $datos,
                auth()->user(),
                isset($d['inhumado_id']) ? Inhumado::find($d['inhumado_id']) : null,
                $motivo,
                $d['causa_fallecimiento'] ?? null,
                (bool) ($d['por_orden_autoridad'] ?? false),
            );
        } catch (ValidationException $e) {
            // Las reglas del trámite hablan de «finado.nombre»; en el formulario es «data.datos.finado.nombre».
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [
                $k === 'causa_fallecimiento' ? 'data.causa_fallecimiento' : "data.datos.{$k}" => $m,
            ])->all());
        } catch (AccionBloqueada $e) {
            Avisos::bloqueado($e);

            return;
        }

        Avisos::listo("Trámite {$tramite->folio} capturado", 'Ahora recibe los documentos que trae el solicitante.');
        $this->redirect(TramiteResource::getUrl('view', ['record' => $tramite]));
    }
}
