<?php

namespace App\Http\Controllers;

use App\Enums\Permiso;
use App\Enums\TipoMovimiento;
use App\Services\Documentos\Documento;
use App\Services\Reportes\GeneradorReportes;
use App\Services\Reportes\Periodo;
use App\Support\AccionBloqueada;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Reportes en PDF. Pide «Ver reportes»; los filtros llegan en la dirección, como los eligió la pantalla. */
class ReportesController
{
    public function __construct(private readonly GeneradorReportes $reportes) {}

    public function libro(Request $r): Response
    {
        $d = $r->validate(['desde' => 'nullable|date', 'hasta' => 'nullable|date', 'tipo' => 'nullable|string', 'panteon' => 'nullable|integer']);

        return $this->abrir(fn () => $this->reportes->libro(Periodo::de($d['desde'] ?? null, $d['hasta'] ?? null),
            TipoMovimiento::tryFrom((string) ($d['tipo'] ?? '')), isset($d['panteon']) ? (int) $d['panteon'] : null), 'libro-de-registro');
    }

    public function ingresos(Request $r): Response
    {
        $d = $r->validate(['desde' => 'nullable|date', 'hasta' => 'nullable|date']);

        return $this->abrir(fn () => $this->reportes->ingresos(Periodo::de($d['desde'] ?? null, $d['hasta'] ?? null)), 'ingresos');
    }

    public function mensual(Request $r): Response
    {
        $d = $r->validate(['anio' => 'required|integer|between:2000,2100', 'mes' => 'required|integer|between:1,12']);

        return $this->abrir(fn () => $this->reportes->mensual((int) $d['anio'], (int) $d['mes']), "informe-mensual-{$d['anio']}-{$d['mes']}");
    }

    public function salud(Request $r): Response
    {
        $d = $r->validate(['anio' => 'required|integer|between:2000,2100']);

        return $this->abrir(fn () => $this->reportes->salud((int) $d['anio']), "informe-salud-{$d['anio']}");
    }

    /** @param  Closure(): Documento  $generar */
    private function abrir(Closure $generar, string $nombre): Response
    {
        abort_unless(auth()->user()?->puede(Permiso::ReportesVer), 403);
        try {
            $documento = $generar();
        } catch (AccionBloqueada $e) {
            abort(409, $e->motivos[0]->problema.' '.$e->motivos[0]->queHacer);
        }

        return $documento->pdf()->stream("{$nombre}.pdf")->header('Cache-Control', 'private, no-store');
    }
}
