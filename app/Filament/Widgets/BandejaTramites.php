<?php

namespace App\Filament\Widgets;

use App\Enums\EstadoTramite;
use App\Enums\Permiso;
use App\Enums\TipoTramite;
use App\Filament\Resources\Tramites\TramiteResource;
use App\Models\Tramite;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Bandeja del día: cuántos trámites esperan algo que el usuario puede hacer, con enlace directo.
 * Cada quien ve sólo las etapas que le tocan según sus permisos.
 */
class BandejaTramites extends Widget
{
    protected string $view = 'filament.widgets.bandeja-tramites';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        return auth()->user()?->puede(Permiso::TramitesVer) ?? false;
    }

    protected function getViewData(): array
    {
        $u = auth()->user();
        $cuenta = fn (array $estados, ?array $tipos = null) => Tramite::whereIn('estado', array_map(fn ($e) => $e->value, $estados))
            ->when($tipos !== null, fn ($q) => $q->whereIn('tipo', array_map(fn ($t) => $t->value, $tipos)))->count();
        $url = fn (string $tab) => TramiteResource::getUrl('index', ['tab' => $tab]);
        $tarjetas = [];

        if ($u->puede(Permiso::TramitesCapturar) || $u->puede(Permiso::TramitesPublicar)) {
            $tarjetas[] = ['n' => $cuenta([EstadoTramite::Capturado, EstadoTramite::EnRevision, EstadoTramite::EnPublicacion]),
                'titulo' => 'Por revisar', 'texto' => 'Expedientes por completar o publicar.', 'url' => $url('revision')];
        }
        if ($u->puede(Permiso::TramitesRegistrarPagos)) {
            $tarjetas[] = ['n' => $cuenta([EstadoTramite::PorPagar]),
                'titulo' => 'Por pagar', 'texto' => 'Esperan el recibo de Tesorería.', 'url' => $url('por_pagar')];
        }
        $autoriza = array_values(array_filter(TipoTramite::cases(), fn (TipoTramite $t) => $u->puede(Permiso::autorizar($t))));
        if ($autoriza !== []) {
            $tarjetas[] = ['n' => $cuenta([EstadoTramite::PorAutorizar], $autoriza),
                'titulo' => 'Por autorizar', 'texto' => 'Esperan tu autorización.', 'url' => $url('por_autorizar')];
        }
        if ($u->puede(Permiso::TramitesCapturar)) {
            $caducan = Tramite::whereNotNull('caduca_en')->whereBetween('caduca_en', [Carbon::today(), Carbon::today()->addDays(30)])
                ->whereNotIn('estado', [EstadoTramite::Concluido->value, EstadoTramite::Rechazado->value, EstadoTramite::Caducado->value])->count();
            if ($caducan > 0) {
                $tarjetas[] = ['n' => $caducan, 'titulo' => 'Caducan pronto', 'texto' => 'Adquisiciones que vencen en 30 días o menos.', 'url' => $url('en_curso'), 'alerta' => true];
            }
        }

        return [
            'tarjetas' => $tarjetas,
            'nuevo' => TramiteResource::canCreate() ? TramiteResource::getUrl('create') : null,
        ];
    }
}
