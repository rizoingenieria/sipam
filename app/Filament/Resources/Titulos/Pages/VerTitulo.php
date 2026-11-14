<?php

namespace App\Filament\Resources\Titulos\Pages;

use App\Enums\EstadoTitulo;
use App\Enums\RolTitularidad;
use App\Enums\TipoTramite;
use App\Filament\Resources\Finados\FinadoResource;
use App\Filament\Resources\Titulos\TituloResource;
use App\Filament\Resources\Tramites\TramiteResource;
use App\Filament\Support\AcervoEnFicha;
use App\Filament\Support\Ficha;
use App\Services\Documentos\DocumentosDelTramite;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/** Ficha del título: titulares, vigencia, quién está sepultado en el espacio y su cadena de títulos. */
class VerTitulo extends ViewRecord
{
    protected static string $resource = TituloResource::class;

    protected static ?string $breadcrumb = 'Ficha';

    public function getTitle(): string|Htmlable
    {
        return 'Título '.$this->record->folio;
    }

    public function getSubheading(): ?string
    {
        return 'Espacio '.$this->record->espacio->clave_completa.' · '.$this->record->estado->getLabel();
    }

    public function infolist(Schema $schema): Schema
    {
        $t = $this->record;

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->columnSpanFull()->schema([
                Section::make('Datos del título')->schema([Text::make(fn () => Ficha::html([
                    'Estado' => $t->estado->getLabel(),
                    'Tipo' => $t->regimen->nombre,
                    'Origen' => $t->origen->getLabel(),
                    'Emitido' => Formato::fecha($t->fecha_emision),
                    'Vence' => TituloResource::vence($t),
                    'Expediente' => $t->expediente,
                    'Título anterior' => $t->tituloAnterior?->folio,
                    'Título que lo sustituyó' => $t->tituloSiguiente?->folio,
                    'Observaciones' => $t->observaciones,
                ]))]),
                Section::make('Personas')->schema([Text::make(fn () => Ficha::html(
                    $t->personas()->orderBy('titularidades.orden')->get()
                        ->mapWithKeys(fn ($p) => [$p->nombre_completo => ($p->pivot->rol instanceof RolTitularidad ? $p->pivot->rol : RolTitularidad::from($p->pivot->rol))->getLabel()])->all()
                        ?: ['Titular' => 'Sin titular registrado']
                ))]),
            ]),
            Section::make('Sepultados en el espacio')
                ->columnSpanFull()
                ->schema([Text::make(fn () => $this->sepultados())]),
            AcervoEnFicha::seccion($this->record, 'titulo'),
        ]);
    }

    private function sepultados(): HtmlString
    {
        $lista = $this->record->espacio->inhumados()->orderByDesc('fecha_inhumacion')->get();
        if ($lista->isEmpty()) {
            return new HtmlString('<p style="margin:0">Nadie.</p>');
        }

        return new HtmlString('<ul style="margin:0;padding-left:1.1rem">'.$lista->map(fn ($i) => '<li><a href="'.e(FinadoResource::getUrl('view', ['record' => $i])).'" style="text-decoration:underline">'
            .e($i->nombre_completo).'</a> · '.e($i->estado->getLabel()).($i->fecha_inhumacion ? ' · inhumado el '.e(Formato::fecha($i->fecha_inhumacion)) : '').'</li>')->implode('').'</ul>');
    }

    protected function getHeaderActions(): array
    {
        $t = $this->record;
        $iniciar = fn (TipoTramite $tipo, string $etiqueta, Heroicon $icono) => Action::make('iniciar_'.$tipo->value)
            ->label($etiqueta)->icon($icono)
            ->url(TramiteResource::getUrl('create', ['tipo' => $tipo->value, 'espacio' => $t->espacio_id]));

        return [
            Action::make('imprimir')
                ->label('Imprimir título')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(DocumentosDelTramite::urlTitulo($t), shouldOpenInNewTab: true)
                ->visible(DocumentosDelTramite::puedeImprimirTitulos()),
            ActionGroup::make([
                $iniciar(TipoTramite::Inhumacion, 'Inhumación en este espacio', Heroicon::OutlinedArrowDownTray),
                $iniciar(TipoTramite::Regularizacion, 'Cambio de propietario', Heroicon::OutlinedArrowsRightLeft),
                $iniciar(TipoTramite::Construccion, 'Permiso de construcción', Heroicon::OutlinedBuildingLibrary),
            ])
                ->label('Iniciar un trámite')
                ->icon(Heroicon::OutlinedPlus)
                ->button()
                ->visible(fn () => $t->estado === EstadoTitulo::Vigente && TramiteResource::canCreate()),
        ];
    }
}
