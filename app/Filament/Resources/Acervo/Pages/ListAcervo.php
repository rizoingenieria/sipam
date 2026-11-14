<?php

namespace App\Filament\Resources\Acervo\Pages;

use App\Filament\Resources\Acervo\AcervoResource;
use App\Models\Espacio;
use App\Models\Inhumado;
use App\Models\Titulo;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * Listado del acervo. Desde la ficha de un espacio, título o finado se llega aquí ya filtrado
 * (?espacio=, ?titulo=, ?inhumado=) con un botón para registrar un documento ya ligado.
 */
class ListAcervo extends ListRecords
{
    protected static string $resource = AcervoResource::class;

    #[Url]
    public ?int $espacio = null;

    #[Url]
    public ?int $titulo = null;

    #[Url]
    public ?int $inhumado = null;

    public function getSubheading(): string|Htmlable|null
    {
        return match (true) {
            $this->espacio !== null => 'Documentos del espacio '.(Espacio::find($this->espacio)?->clave_completa ?? $this->espacio),
            $this->titulo !== null => 'Documentos del título '.(Titulo::find($this->titulo)?->folio ?? $this->titulo),
            $this->inhumado !== null => 'Documentos de '.(Inhumado::find($this->inhumado)?->nombre_completo ?? $this->inhumado),
            default => 'Originales en papel escaneados y guardados tal cual. Búscalos por nombre, folio, caja o libro, o por la persona, título o espacio al que se refieren.',
        };
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();
        // Los retirados no estorban en el listado; se ven sólo pidiéndolos. Su ficha sigue abriendo.
        if (! ($this->tableFilters['retirados']['isActive'] ?? false)) {
            $query->whereNull('retirado_en');
        }

        return match (true) {
            $this->espacio !== null => $query->whereHas('espacios', fn (Builder $q) => $q->whereKey($this->espacio)),
            $this->titulo !== null => $query->whereHas('titulos', fn (Builder $q) => $q->whereKey($this->titulo)),
            $this->inhumado !== null => $query->whereHas('inhumados', fn (Builder $q) => $q->whereKey($this->inhumado)),
            default => $query,
        };
    }

    protected function getHeaderActions(): array
    {
        $filtro = array_filter(['espacio' => $this->espacio, 'titulo' => $this->titulo, 'inhumado' => $this->inhumado]);

        return [
            Action::make('todos')->label('Ver todo el acervo')->color('gray')
                ->url(AcervoResource::getUrl('index'))
                ->visible($filtro !== []),
            Action::make('nuevo')->label('Registrar documento')->icon(Heroicon::OutlinedPlus)
                ->url(AcervoResource::getUrl('create', $filtro))
                ->visible(fn () => AcervoResource::canCreate()),
        ];
    }
}
