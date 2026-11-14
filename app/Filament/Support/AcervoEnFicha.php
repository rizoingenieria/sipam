<?php

namespace App\Filament\Support;

use App\Enums\Permiso;
use App\Filament\Resources\Acervo\AcervoResource;
use App\Models\DocumentoAcervo;
use Filament\Actions\Action;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Sección «Acervo histórico» en las fichas de finados y títulos: los documentos escaneados ligados al registro,
 * y un botón para registrar uno nuevo ya ligado. Sólo la ve quien puede consultar el acervo.
 */
final class AcervoEnFicha
{
    /** @param  'espacio'|'titulo'|'inhumado'  $parametro */
    public static function seccion(Model $registro, string $parametro): Section
    {
        $puedeVer = fn () => (bool) auth()->user()?->puede(Permiso::AcervoConsultar);

        return Section::make('Acervo histórico')
            ->description('Documentos originales escaneados que se refieren a este registro.')
            ->columnSpanFull()
            ->visible($puedeVer)
            ->headerActions([
                Action::make('acervoTodos')->label('Ver en el acervo')->link()
                    ->url(fn () => AcervoResource::getUrl('index', [$parametro => $registro->getKey()])),
                Action::make('acervoNuevo')->label('Registrar documento')->icon('heroicon-o-plus')->button()->size('sm')
                    ->url(fn () => AcervoResource::getUrl('create', [$parametro => $registro->getKey()]))
                    ->visible(fn () => AcervoResource::canCreate()),
            ])
            ->schema([Html::make(fn () => self::lista($registro))]);
    }

    public static function lista(Model $registro): HtmlString
    {
        $documentos = $registro->documentosAcervo()->with('tipo')->withCount('archivos')->get();
        if ($documentos->isEmpty()) {
            return new HtmlString('<p style="margin:0;color:var(--sp-ink-soft)">No hay documentos escaneados ligados todavía.</p>');
        }

        $html = '<ul style="margin:0;padding:0;list-style:none;display:grid;gap:.6rem">';
        foreach ($documentos as $d) {
            /** @var DocumentoAcervo $d */
            $detalle = collect([$d->folio, $d->tipo?->nombre, $d->periodo(), $d->archivos_count.' '.($d->archivos_count === 1 ? 'archivo' : 'archivos')])->filter()->implode(' · ');
            $html .= '<li><a href="'.e(AcervoResource::getUrl('view', ['record' => $d])).'" style="font-weight:600">'.e($d->titulo).'</a>'
                .'<div style="color:var(--sp-ink-soft);font-size:.92rem">'.e($detalle).($d->ubicacion_fisica ? ' · Papel: '.e($d->ubicacion_fisica) : '').'</div></li>';
        }

        return new HtmlString($html.'</ul>');
    }
}
