<?php

namespace App\Filament\Pages;

use App\Enums\Permiso;
use App\Filament\Support\Avisos;
use App\Services\CargaInicial\CargaInicial;
use App\Services\CargaInicial\LectorHoja;
use App\Services\CargaInicial\Plantillas;
use App\Services\CargaInicial\ResultadoCarga;
use App\Services\CargaInicial\TipoCarga;
use App\Support\AccionBloqueada;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Carga del registro anterior desde Excel: descargar plantilla, llenarla, revisar y cargar.
 * Nada se guarda hasta que la revisión sale sin errores y alguien confirma.
 */
class CargaInicialPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpOnSquareStack;

    protected static ?string $title = 'Carga inicial';

    protected static ?string $slug = 'carga-inicial';

    protected static ?int $navigationSort = 40;

    public ?array $data = [];

    /** Resultado de la última revisión, para mostrarlo y para saber si ya se puede cargar. */
    public ?array $revision = null;

    /** Archivo revisado, guardado en el disco privado hasta que se carga. */
    public ?array $archivo = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->puede(Permiso::CatalogosInventario) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Pasa al sistema lo que hoy está en libros o en Excel. Descarga la plantilla, llénala, revísala aquí y cárgala. Nada se guarda hasta que la revisión sale sin errores.';
    }

    public function mount(): void
    {
        $this->form->fill(['tipo' => TipoCarga::Espacios->value]);
    }

    private function tipo(): TipoCarga
    {
        $t = $this->data['tipo'] ?? TipoCarga::Espacios;

        return $t instanceof TipoCarga ? $t : TipoCarga::from($t);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('1. ¿Qué vas a cargar?')
                ->description('Sigue el orden: primero espacios, luego títulos y al final las personas sepultadas.')
                ->schema([
                    Radio::make('tipo')->hiddenLabel()->required()->live()
                        ->options(TipoCarga::class)
                        ->descriptions(collect(TipoCarga::cases())->mapWithKeys(fn (TipoCarga $t) => [$t->value => $t->getDescription()])->all())
                        ->afterStateUpdated(fn () => $this->limpiar()),
                    Actions::make([
                        Action::make('plantilla')
                            ->label(fn () => 'Descargar la plantilla de '.Str::after($this->tipo()->getLabel(), '. '))
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->color('gray')
                            ->action(fn () => $this->descargarPlantilla()),
                    ]),
                ]),
            Section::make('2. Sube el archivo lleno')
                ->schema([
                    FileUpload::make('archivo')->hiddenLabel()->storeFiles(false)->required()
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->helperText('Excel (.xlsx) o CSV. Sólo se lee la primera hoja.')
                        ->afterStateUpdated(fn () => $this->limpiar()),
                    Actions::make([
                        Action::make('revisar')->label('Revisar el archivo')->icon(Heroicon::OutlinedMagnifyingGlass)
                            ->action(fn () => $this->revisar()),
                    ]),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form'),
            Section::make('3. Resultado de la revisión')
                ->visible(fn () => $this->revision !== null)
                ->schema([
                    Callout::make(fn () => $this->tituloResultado())
                        ->description(fn () => $this->descripcionResultado())
                        ->color(fn () => $this->revision['errores'] ? 'danger' : 'success')
                        ->icon(fn () => $this->revision['errores'] ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                        ->footerActions([
                            Action::make('cargar')
                                ->label(fn () => 'Cargar '.$this->revision['nuevos'].' '.($this->revision['nuevos'] === 1 ? 'registro' : 'registros'))
                                ->icon(Heroicon::OutlinedArrowUpTray)
                                ->button()
                                ->size('lg')
                                ->requiresConfirmation()
                                ->modalHeading('¿Cargar los registros?')
                                ->modalDescription('Se guardan todos juntos. Si algo falla, no se guarda ninguno. Lo que ya existía se deja igual.')
                                ->modalSubmitActionLabel('Sí, cargar')
                                ->action(fn () => $this->cargar())
                                ->visible(fn () => $this->revision && ! $this->revision['errores'] && $this->revision['nuevos'] > 0),
                        ]),
                    Html::make(fn () => $this->tablaRenglones())->visible(fn () => $this->revision && ($this->revision['errores'] || $this->revision['avisos'])),
                ]),
        ]);
    }

    private function limpiar(): void
    {
        if ($this->archivo) {
            Storage::disk('local')->delete($this->archivo['ruta']);
        }
        $this->revision = null;
        $this->archivo = null;
    }

    public function descargarPlantilla(): BinaryFileResponse
    {
        $ruta = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
        app(Plantillas::class)->crear($this->tipo(), $ruta);

        return response()->download($ruta, Plantillas::nombreArchivo($this->tipo()))->deleteFileAfterSend();
    }

    public function revisar(): void
    {
        $estado = $this->form->getState();
        $subido = $estado['archivo'];
        $this->limpiar();
        $original = $subido->getClientOriginalName();
        $ruta = $subido->storeAs('cargas', Str::uuid().'.'.Str::lower(pathinfo($original, PATHINFO_EXTENSION)), 'local');
        $this->archivo = ['ruta' => $ruta, 'nombre' => $original, 'tipo' => $this->tipo()->value];

        try {
            $filas = app(LectorHoja::class)->leer(Storage::disk('local')->path($ruta), $this->tipo(), $original);
        } catch (AccionBloqueada $e) {
            $this->limpiar();
            Avisos::bloqueado($e);

            return;
        }
        $this->revision = self::comoArreglo(app(CargaInicial::class)->revisar($this->tipo(), $filas));
    }

    public function cargar(): void
    {
        abort_unless($this->archivo && $this->archivo['tipo'] === $this->tipo()->value, 409);
        $filas = app(LectorHoja::class)->leer(Storage::disk('local')->path($this->archivo['ruta']), $this->tipo(), $this->archivo['nombre']);
        $r = app(CargaInicial::class)->cargar($this->tipo(), $filas, $this->archivo['nombre']);

        if (! $r->sinErrores()) {
            // Algo cambió entre la revisión y la carga (otra persona cargó lo mismo): se muestra de nuevo.
            $this->revision = self::comoArreglo($r);

            return;
        }
        $this->limpiar();
        $this->form->fill(['tipo' => $this->tipo()->value, 'archivo' => null]);
        Avisos::listo('Carga terminada', "Se cargaron {$r->nuevos} registros nuevos. {$r->existentes} ya estaban y se dejaron igual.");
    }

    private static function comoArreglo(ResultadoCarga $r): array
    {
        return ['renglones' => $r->renglones, 'nuevos' => $r->nuevos, 'existentes' => $r->existentes, 'errores' => $r->errores, 'avisos' => $r->avisos];
    }

    private function tituloResultado(): string
    {
        $r = $this->revision;
        $n = count($r['errores']);

        return $n > 0
            ? ($n === 1 ? 'Hay 1 renglón con errores' : "Hay {$n} renglones con errores")
            : ($r['nuevos'] > 0 ? 'El archivo está listo para cargarse' : 'No hay nada nuevo que cargar');
    }

    private function descripcionResultado(): string
    {
        $r = $this->revision;
        $base = 'El archivo tiene '.Formato::cantidad($r['renglones'], 'renglón', 'renglones').': '.Formato::cantidad($r['nuevos'], 'nuevo')
            .' y '.$r['existentes'].' que ya '.($r['existentes'] === 1 ? 'estaba' : 'estaban').' en el sistema.';

        return $r['errores']
            ? $base.' Corrige en tu Excel los renglones marcados abajo y vuelve a subirlo. No se ha guardado nada.'
            : $base.(count($r['avisos']) ? ' Revisa los avisos de abajo: no impiden cargar, pero conviene completar esos datos después.' : '');
    }

    private function tablaRenglones(): HtmlString
    {
        $html = '<table style="width:100%;border-collapse:collapse;font-size:.95rem"><thead><tr style="border-bottom:1px solid var(--sp-ink);text-align:left">'
            .'<th style="padding:.45rem .5rem;width:7rem">Renglón</th><th style="padding:.45rem .5rem">Qué corregir o revisar</th></tr></thead><tbody>';
        $renglones = array_unique([...array_keys($this->revision['errores']), ...array_keys($this->revision['avisos'])]);
        sort($renglones);
        foreach (array_slice($renglones, 0, 300) as $n) {
            $html .= '<tr style="border-bottom:1px solid var(--sp-rule);vertical-align:top"><td style="padding:.45rem .5rem;font-weight:700">'.$n.'</td><td style="padding:.45rem .5rem">';
            foreach ($this->revision['errores'][$n] ?? [] as $m) {
                $html .= '<div><span style="color:var(--sp-alert);font-weight:700">Error:</span> '.e($m).'</div>';
            }
            foreach ($this->revision['avisos'][$n] ?? [] as $m) {
                $html .= '<div><span style="color:var(--sp-ink-soft);font-weight:700">Aviso:</span> '.e($m).'</div>';
            }
            $html .= '</td></tr>';
        }
        if (count($renglones) > 300) {
            $html .= '<tr><td colspan="2" style="padding:.5rem">Y '.(count($renglones) - 300).' renglones más. Corrige estos y vuelve a revisar.</td></tr>';
        }

        return new HtmlString($html.'</tbody></table>');
    }
}
