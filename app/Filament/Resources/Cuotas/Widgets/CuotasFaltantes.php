<?php

namespace App\Filament\Resources\Cuotas\Widgets;

use App\Services\Diagnostico\DiagnosticoConfiguracion;
use Filament\Widgets\Widget;

/** Lista de las cuotas que faltan capturar. Desaparece cuando no falta ninguna. */
class CuotasFaltantes extends Widget
{
    protected string $view = 'filament.widgets.cuotas-faltantes';

    protected int|string|array $columnSpan = 'full';

    /** Se vuelve a dibujar cuando se captura una cuota. */
    protected $listeners = ['cuotas-actualizadas' => '$refresh'];

    protected function getViewData(): array
    {
        return ['faltan' => app(DiagnosticoConfiguracion::class)->cuotasFaltantes()];
    }

    public static function canView(): bool
    {
        return app(DiagnosticoConfiguracion::class)->cuotasFaltantes() !== [];
    }
}
