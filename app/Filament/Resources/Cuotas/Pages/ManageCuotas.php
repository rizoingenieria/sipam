<?php

namespace App\Filament\Resources\Cuotas\Pages;

use App\Filament\Resources\Cuotas\CuotaResource;
use App\Filament\Resources\Cuotas\Widgets\CuotasFaltantes;
use App\Filament\Support\Avisos;
use App\Models\Cuota;
use App\Support\AccionBloqueada;
use App\Support\Motivo;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

class ManageCuotas extends ManageRecords
{
    protected static string $resource = CuotaResource::class;

    public function getSubheading(): ?string
    {
        return 'Importes que aprobó el Cabildo. Cambiar uno no altera las órdenes de pago ya emitidas.';
    }

    protected function getHeaderWidgets(): array
    {
        return [CuotasFaltantes::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Capturar cuota')
                ->modalHeading('Capturar cuota')
                ->modalSubmitActionLabel('Guardar cuota')
                ->createAnother(false)
                ->after(fn () => $this->dispatch('cuotas-actualizadas'))
                ->using(function (array $data, Action $action): Cuota {
                    try {
                        return Cuota::create($data);
                    } catch (UniqueConstraintViolationException) {
                        Avisos::bloqueado(AccionBloqueada::por(Motivo::de(
                            'Ese concepto ya tiene una cuota vigente.',
                            'Búscalo en la lista y usa «Cambiar importe»; así el importe anterior queda en el historial.',
                        )));
                    } catch (InvalidArgumentException $e) {
                        Avisos::bloqueado(AccionBloqueada::por($e->getMessage()));
                    }
                    $action->halt();
                }),
        ];
    }
}
