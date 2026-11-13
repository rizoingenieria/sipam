<?php

namespace App\Services\Reportes;

use App\Enums\Permiso;
use App\Enums\TipoMovimiento;
use App\Models\Panteon;
use App\Services\Documentos\Documento;
use App\Services\Documentos\GeneradorDocumentos;
use App\Support\AccionBloqueada;
use App\Support\EnLetra;
use App\Support\Motivo;
use Illuminate\Support\Carbon;

/** Los reportes en PDF, con el mismo membrete y pie que los documentos oficiales. */
final class GeneradorReportes
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private readonly GeneradorDocumentos $documentos, private readonly Informes $informes) {}

    public function libro(Periodo $p, ?TipoMovimiento $tipo = null, ?int $panteonId = null): Documento
    {
        $consulta = LibroRegistro::consulta($p, $tipo, $panteonId);
        $n = (clone $consulta)->count();
        if ($n > LibroRegistro::LIMITE_PDF) {
            throw AccionBloqueada::por(Motivo::de("El periodo tiene {$n} movimientos; el PDF admite hasta ".LibroRegistro::LIMITE_PDF.'.',
                'Elige un periodo más corto o descarga el Excel, que no tiene límite.'));
        }
        $verCausa = auth()->user()?->puede(Permiso::InhumadosVerCausa) ?? false;

        return $this->documentos->documento('pdf.reportes.libro', 'Libro de registro', null, Carbon::now(), [
            'periodo' => $p,
            'tipo' => $tipo,
            'panteon' => $panteonId ? Panteon::find($panteonId)?->nombre : null,
            'verCausa' => $verCausa,
            'columnas' => LibroRegistro::COLUMNAS,
            'renglones' => $consulta->get()->map(fn ($m) => LibroRegistro::renglon($m, $verCausa))->all(),
            'fundamento' => 'Art. 21, frs. III y IV',
            'firmas' => [$this->documentos->firma('encargado_panteones')],
        ], horizontal: true);
    }

    public function ingresos(Periodo $p): Documento
    {
        return $this->documentos->documento('pdf.reportes.ingresos', 'Reporte de ingresos', null, Carbon::now(), [
            'periodo' => $p,
            'resumen' => Ingresos::porConcepto($p),
            'ordenes' => Ingresos::consulta($p)->get(),
            'pendientes' => Ingresos::pendientes($p),
            'fundamento' => 'Art. 20, fr. XII, inciso d); arts. 65, 69 y 70',
            'firmas' => [$this->documentos->firma('encargado_panteones')],
        ]);
    }

    public function mensual(int $anio, int $mes): Documento
    {
        $datos = $this->informes->mensual($anio, $mes);

        return $this->documentos->documento('pdf.reportes.mensual', 'Informe mensual', null, Carbon::now(), $datos + [
            'nombreMes' => self::MESES[$mes - 1].' de '.$anio,
            'generado' => EnLetra::fecha(Carbon::now()),
            'fundamento' => 'Art. 21, frs. IV, XXIII y XXIV; art. 22, fr. IV',
            'firmas' => [$this->documentos->firma('encargado_panteones')],
        ]);
    }

    public function salud(int $anio): Documento
    {
        return $this->documentos->documento('pdf.reportes.salud', 'Informe anual', null, Carbon::now(), $this->informes->anualSalud($anio) + [
            'fundamento' => 'Art. 21, fr. XXX',
            'firmas' => [$this->documentos->firma('encargado_panteones')],
        ], horizontal: true);
    }

    public static function nombreMes(int $mes): string
    {
        return self::MESES[$mes - 1];
    }
}
