<?php

namespace App\Filament\Resources\Acervo\Pages;

use App\Filament\Resources\Acervo\AcervoResource;
use App\Filament\Support\Avisos;
use App\Services\Acervo\AcervoHistorico;
use App\Support\AccionBloqueada;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/** Corregir los datos y vínculos de un documento. Los archivos no se cambian: se agregan desde la ficha. */
class EditarDocumento extends EditRecord
{
    protected static string $resource = AcervoResource::class;

    protected static ?string $breadcrumb = 'Corregir';

    public function getTitle(): string|Htmlable
    {
        return 'Corregir '.$this->record->folio;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + [
            'espacios' => $this->record->espacios()->pluck('espacios.id')->all(),
            'titulos' => $this->record->titulos()->pluck('titulos.id')->all(),
            'inhumados' => $this->record->inhumados()->pluck('inhumados.id')->all(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(AcervoHistorico::class)->actualizar($record, $data,
                ['espacios' => $data['espacios'] ?? [], 'titulos' => $data['titulos'] ?? [], 'inhumados' => $data['inhumados'] ?? []],
                auth()->user());
        } catch (AccionBloqueada $e) {
            Avisos::bloqueado($e);
            throw new Halt;
        }
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Se guardaron los cambios';
    }

    protected function getRedirectUrl(): string
    {
        return AcervoResource::getUrl('view', ['record' => $this->record]);
    }
}
