<?php

namespace App\Filament\Resources\Acervo\Pages;

use App\Filament\Resources\Acervo\AcervoResource;
use App\Filament\Resources\Finados\FinadoResource;
use App\Filament\Resources\Titulos\TituloResource;
use App\Filament\Support\Avisos;
use App\Filament\Support\CamposEspacio;
use App\Filament\Support\Ficha;
use App\Models\ArchivoAcervo;
use App\Models\DocumentoAcervo;
use App\Services\Acervo\AcervoHistorico;
use App\Support\Formato;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/** Ficha del documento: qué es, sus archivos escaneados, a qué se refiere y, si aplica, por qué se retiró. */
class VerDocumento extends ViewRecord
{
    protected static string $resource = AcervoResource::class;

    protected static ?string $breadcrumb = 'Ficha';

    public function getTitle(): string|Htmlable
    {
        return $this->record->titulo;
    }

    public function getSubheading(): ?string
    {
        return collect([$this->record->folio, $this->record->tipo?->nombre, $this->record->periodo()])->filter()->implode(' · ');
    }

    public function infolist(Schema $schema): Schema
    {
        /** @var DocumentoAcervo $d */
        $d = $this->record;

        return $schema->components([
            Section::make('Este documento fue retirado')
                ->visible($d->retirado())
                ->schema([Text::make(fn () => Ficha::html([
                    'Motivo' => $d->motivo_retiro,
                    'Retirado por' => $d->retiradoPor?->name,
                    'Fecha' => $d->retirado_en ? Formato::fecha($d->retirado_en) : null,
                    'Archivos' => 'Se conservan en el servidor y en el respaldo; ya no aparece en las fichas ni en las búsquedas.',
                ]))]),
            Grid::make(['default' => 1, 'lg' => 2])->columnSpanFull()->schema([
                Section::make('Datos')->schema([Text::make(fn () => Ficha::html([
                    'Folio' => $d->folio,
                    'Tipo' => $d->tipo?->nombre,
                    'De cuándo' => $d->periodo() ?? 'Sin año registrado',
                    'Dónde está el papel' => $d->ubicacion_fisica ?? 'Sin registrar',
                    'Qué contiene' => $d->descripcion,
                    'Observaciones' => $d->observaciones,
                    'Registrado por' => $d->capturadoPor?->name.' el '.Formato::fecha($d->created_at),
                ]))]),
                Section::make('A qué se refiere')
                    ->headerActions([
                        Action::make('ligar')->label('Ligar a más registros')->link()
                            ->url(fn () => AcervoResource::getUrl('edit', ['record' => $d]))
                            ->visible(fn () => AcervoResource::canEdit($d)),
                    ])
                    ->schema([Html::make(fn () => $this->vinculos())]),
            ]),
            Section::make('Archivos escaneados')
                ->description('Guardados tal como se subieron. La huella digital (SHA-256) permite comprobar que un archivo no ha cambiado.')
                ->columnSpanFull()
                ->schema([Html::make(fn () => $this->archivos())]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $servicio = app(AcervoHistorico::class);

        return [
            Action::make('agregarArchivos')->label('Agregar páginas')->icon(Heroicon::OutlinedDocumentPlus)
                ->visible(fn () => AcervoResource::canEdit($this->record))
                ->modalHeading('Agregar páginas o archivos')
                ->modalDescription('Se agregan al final, en el orden en que los acomodes. Los archivos que ya están no cambian.')
                ->modalSubmitActionLabel('Agregar')
                ->schema([
                    FileUpload::make('archivos')->label('Archivos escaneados')->required()
                        ->multiple()->reorderable()->appendFiles()->storeFiles(false)
                        // De uno en uno: Filament guarda en el orden en que terminan de subir; así coincide con el que se ve.
                        ->maxParallelUploads(1)
                        ->acceptedFileTypes(array_keys(AcervoHistorico::TIPOS))
                        ->maxSize(fn () => $servicio->tamanoMaximoMb() * 1024)
                        ->helperText(fn () => $servicio->tiposEnPalabras().' de hasta '.$servicio->tamanoMaximoMb().' MB cada uno.'),
                ])
                ->action(function (array $data, Action $action) use ($servicio): void {
                    Avisos::intentar(fn () => $servicio->agregarArchivos($this->record, array_values($data['archivos']), auth()->user()), $action);
                    Avisos::listo('Se agregaron '.count($data['archivos']).' '.(count($data['archivos']) === 1 ? 'archivo' : 'archivos'));
                    $this->refrescar();
                }),
            Action::make('corregir')->label('Corregir datos')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                ->url(fn () => AcervoResource::getUrl('edit', ['record' => $this->record]))
                ->visible(fn () => AcervoResource::canEdit($this->record)),
            Action::make('retirar')->label('Retirar')->icon(Heroicon::OutlinedArchiveBoxXMark)->color('danger')
                ->visible(fn () => auth()->user()->can('retirar', $this->record))
                ->modalHeading(fn () => '¿Retirar el documento '.$this->record->folio.'?')
                ->modalDescription('Úsalo sólo si se subió por error o está duplicado. Dejará de aparecer en las fichas y en las búsquedas, pero los archivos NO se borran: se conservan en el servidor y en el respaldo.')
                ->modalSubmitActionLabel('Sí, retirarlo')
                ->schema([
                    Textarea::make('motivo')->label('¿Por qué se retira?')->required()->minLength(10)->maxLength(500)->rows(3)
                        ->placeholder('Ej.: Se registró dos veces; el bueno es ACV-2026-000015.'),
                ])
                ->action(function (array $data, Action $action) use ($servicio): void {
                    Avisos::intentar(fn () => $servicio->retirar($this->record, $data['motivo'], auth()->user()), $action);
                    Avisos::listo('Documento retirado', 'Sus archivos se conservan.');
                    $this->refrescar();
                }),
        ];
    }

    private function refrescar(): void
    {
        $this->record = $this->record->fresh();
        $this->cacheSchema('infolist', null);
    }

    private function vinculos(): HtmlString
    {
        $d = $this->record;
        $usuario = auth()->user();
        $grupos = [];

        foreach ($d->espacios()->get() as $e) {
            $grupos['Espacios'][] = [CamposEspacio::etiqueta($e), AcervoResource::getUrl('index', ['espacio' => $e->id]), 'Ver sus documentos'];
        }
        foreach ($d->titulos()->with('titulares')->get() as $t) {
            $grupos['Títulos'][] = [AcervoResource::etiquetaTitulo($t), $usuario->can('view', $t) ? TituloResource::getUrl('view', ['record' => $t]) : null, 'Abrir ficha'];
        }
        foreach ($d->inhumados()->with('espacio')->get() as $i) {
            $grupos['Personas sepultadas'][] = [AcervoResource::etiquetaInhumado($i), $usuario->can('view', $i) ? FinadoResource::getUrl('view', ['record' => $i]) : null, 'Abrir ficha'];
        }

        if ($grupos === []) {
            return new HtmlString('<p style="margin:0;color:var(--sp-ink-soft)">Todavía no está ligado a ningún espacio, título ni persona. Usa «Ligar a más registros» para que aparezca en sus fichas.</p>');
        }

        $html = '<div style="display:grid;gap:1rem">';
        foreach ($grupos as $titulo => $filas) {
            $html .= '<div><div style="font-weight:600;margin-bottom:.35rem">'.e($titulo).'</div><ul style="margin:0;padding-left:1.1rem;display:grid;gap:.3rem">';
            foreach ($filas as [$texto, $url, $accion]) {
                $html .= '<li>'.e($texto).($url ? ' · <a href="'.e($url).'" style="font-weight:600">'.e($accion).'</a>' : '').'</li>';
            }
            $html .= '</ul></div>';
        }

        return new HtmlString($html.'</div>');
    }

    private function archivos(): HtmlString
    {
        $archivos = $this->record->archivos()->with('subidoPor')->get();
        $celda = 'padding:.6rem .75rem;border-top:1px solid var(--sp-rule);vertical-align:top';

        $html = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.95rem">'
            .'<thead><tr style="text-align:left;color:var(--sp-ink-soft)">'
            .'<th style="padding:.4rem .75rem">#</th><th style="padding:.4rem .75rem">Archivo</th><th style="padding:.4rem .75rem">Tamaño</th>'
            .'<th style="padding:.4rem .75rem">Subido</th><th style="padding:.4rem .75rem">Huella</th><th style="padding:.4rem .75rem"></th></tr></thead><tbody>';

        foreach ($archivos as $a) {
            /** @var ArchivoAcervo $a */
            $ver = route('filament.panteones.acervo.archivo', $a);
            $abrir = $a->esPdf() || $a->esImagenVisible()
                ? '<a href="'.e($ver).'" target="_blank" rel="noopener" style="font-weight:600">Ver</a> · '
                : '';
            $estado = match ($a->integro) {
                true => '<span style="color:var(--sp-guide)">verificado '.e(Formato::fecha($a->verificado_en)).'</span>',
                false => '<strong style="color:var(--sp-alert)">¡NO COINCIDE! Avisa a soporte técnico</strong>',
                default => '<span style="color:var(--sp-ink-soft)">sin verificar aún</span>',
            };

            $html .= '<tr>'
                .'<td style="'.$celda.'">'.$a->orden.'</td>'
                .'<td style="'.$celda.'"><div style="font-weight:600;word-break:break-all">'.e($a->nombre_original).'</div>'
                .'<div style="color:var(--sp-ink-soft)">'.e(AcervoHistorico::TIPOS[$a->mime][1] ?? $a->mime).'</div></td>'
                .'<td style="'.$celda.';white-space:nowrap">'.e($a->tamano()).'</td>'
                .'<td style="'.$celda.'">'.e(Formato::fecha($a->created_at)).'<div style="color:var(--sp-ink-soft)">'.e($a->subidoPor?->name).'</div></td>'
                .'<td style="'.$celda.'"><code title="'.e($a->sha256).'" style="font-size:.85rem">'.e(substr($a->sha256, 0, 12)).'…</code><div style="font-size:.85rem">'.$estado.'</div></td>'
                .'<td style="'.$celda.';white-space:nowrap;text-align:right">'.$abrir.'<a href="'.e($ver.'?descargar=1').'" style="font-weight:600">Descargar</a></td>'
                .'</tr>';
        }

        return new HtmlString($html.'</tbody></table></div>');
    }
}
