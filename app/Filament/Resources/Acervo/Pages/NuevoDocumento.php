<?php

namespace App\Filament\Resources\Acervo\Pages;

use App\Filament\Resources\Acervo\AcervoResource;
use App\Filament\Support\Avisos;
use App\Services\Acervo\AcervoHistorico;
use App\Support\AccionBloqueada;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/** Registrar un documento escaneado. Si se llega desde una ficha, ya viene ligado a ese registro. */
class NuevoDocumento extends CreateRecord
{
    protected static string $resource = AcervoResource::class;

    protected static ?string $title = 'Registrar documento del acervo';

    protected static ?string $breadcrumb = 'Registrar';

    protected static bool $canCreateAnother = false;

    protected function fillForm(): void
    {
        $this->form->fill(array_filter([
            'espacios' => request()->integer('espacio') ? [request()->integer('espacio')] : null,
            'titulos' => request()->integer('titulo') ? [request()->integer('titulo')] : null,
            'inhumados' => request()->integer('inhumado') ? [request()->integer('inhumado')] : null,
        ]));
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(AcervoHistorico::class)->registrar(
                $data,
                array_values($data['archivos'] ?? []),
                ['espacios' => $data['espacios'] ?? [], 'titulos' => $data['titulos'] ?? [], 'inhumados' => $data['inhumados'] ?? []],
                auth()->user(),
            );
        } catch (AccionBloqueada $e) {
            Avisos::bloqueado($e);
            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Documento '.$this->record->folio.' guardado en el acervo';
    }

    protected function getRedirectUrl(): string
    {
        return AcervoResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Guardar en el acervo');
    }
}
