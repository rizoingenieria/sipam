<?php

namespace App\Filament\Widgets;

use App\Enums\Permiso;
use App\Filament\Pages\DatosAyuntamiento;
use App\Filament\Resources\Cuotas\CuotaResource;
use App\Filament\Resources\Firmantes\FirmanteResource;
use App\Filament\Resources\Panteones\PanteonResource;
use App\Filament\Resources\Parametros\ParametroResource;
use App\Filament\Resources\Usuarios\UsuarioResource;
use App\Services\Diagnostico\DiagnosticoConfiguracion;
use App\Services\Diagnostico\Pendiente;
use Filament\Widgets\Widget;

/**
 * Lista de arranque: lo que falta configurar, en lenguaje llano, con el paso que lo resuelve
 * y un enlace directo a la pantalla donde se hace. La ven quienes configuran o administran.
 */
class ListaArranque extends Widget
{
    protected string $view = 'filament.widgets.lista-arranque';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        $u = auth()->user();

        return $u !== null && collect([
            Permiso::AdministrarUsuarios, Permiso::CatalogosReglas, Permiso::CatalogosTabulador,
            Permiso::CatalogosFirmantes, Permiso::CatalogosInventario,
        ])->contains(fn (Permiso $p) => $u->puede($p));
    }

    protected function getViewData(): array
    {
        $pendientes = app(DiagnosticoConfiguracion::class)->revisar();

        return [
            'pendientes' => $pendientes->map(fn (Pendiente $p) => [
                'bloquea' => $p->esBloqueante(),
                'area' => $p->area,
                'problema' => $p->motivo->problema,
                'queHacer' => $p->motivo->queHacer,
                'enlace' => $this->enlace($p->area),
            ]),
            'bloqueantes' => $pendientes->filter->esBloqueante()->count(),
        ];
    }

    /** @return array{0: string, 1: string}|null [texto, url] si el usuario puede abrir esa pantalla. */
    private function enlace(string $area): ?array
    {
        [$texto, $destino] = match ($area) {
            'Usuarios' => ['Ir a usuarios', UsuarioResource::class],
            'Inventario' => ['Ir a panteones', PanteonResource::class],
            'Parámetros' => ['Ir a parámetros', ParametroResource::class],
            'Tabulador' => ['Ir al tabulador', CuotaResource::class],
            'Firmantes' => ['Ir a firmantes', FirmanteResource::class],
            'Membrete' => ['Ir a datos del ayuntamiento', DatosAyuntamiento::class],
            default => [null, null],
        };

        if ($destino === null || ! $destino::canAccess()) {
            return null;
        }

        return [$texto, $destino::getUrl()];
    }
}
