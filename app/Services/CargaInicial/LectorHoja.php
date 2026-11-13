<?php

namespace App\Services\CargaInicial;

use App\Support\AccionBloqueada;
use App\Support\Motivo;
use DateTimeInterface;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as OpcionesCsv;
use OpenSpout\Reader\CSV\Reader as LectorCsv;
use OpenSpout\Reader\XLSX\Reader as LectorXlsx;
use Throwable;

/**
 * Lee la primera hoja de un Excel (.xlsx) o un .csv y devuelve los renglones con las columnas de la plantilla.
 * Reconoce los encabezados aunque cambien mayúsculas o acentos; los renglones vacíos se ignoran.
 */
final class LectorHoja
{
    public const MAXIMO_RENGLONES = 20000;

    /** @return array<int, array<string, mixed>> número de renglón de Excel => valores */
    public function leer(string $ruta, TipoCarga $tipo, ?string $nombreOriginal = null): array
    {
        $extension = Str::lower(pathinfo($nombreOriginal ?? $ruta, PATHINFO_EXTENSION));
        $lector = match ($extension) {
            'xlsx' => new LectorXlsx,
            'csv', 'txt' => new LectorCsv(tap(new OpcionesCsv, fn ($o) => $o->FIELD_DELIMITER = $this->delimitador($ruta))),
            default => throw AccionBloqueada::por(Motivo::de('El archivo no es de Excel (.xlsx) ni CSV.', 'Guárdalo como «Libro de Excel (.xlsx)» y súbelo de nuevo.')),
        };

        try {
            $lector->open($ruta);
        } catch (Throwable) {
            throw AccionBloqueada::por(Motivo::de('No se pudo abrir el archivo.', 'Revisa que no esté dañado ni protegido con contraseña.'));
        }

        $columnas = collect($tipo->columnas())->keyBy(fn (Columna $c) => self::normalizar($c->encabezado));
        $porClave = collect($tipo->columnas())->keyBy('clave');
        $mapa = null;
        $filas = [];

        try {
            foreach ($lector->getSheetIterator() as $hoja) {
                foreach ($hoja->getRowIterator() as $n => $fila) {
                    $valores = $fila->toArray();
                    if ($mapa === null) {
                        $mapa = $this->mapear($valores, $columnas, $porClave, $tipo);

                        continue;
                    }
                    $datos = [];
                    foreach ($mapa as $indice => $clave) {
                        $datos[$clave] = self::limpiar($valores[$indice] ?? null);
                    }
                    if (collect($datos)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty()) {
                        continue;
                    }
                    if (count($filas) >= self::MAXIMO_RENGLONES) {
                        throw AccionBloqueada::por(Motivo::de('El archivo tiene más de '.number_format(self::MAXIMO_RENGLONES).' renglones.', 'Divídelo en varios archivos.'));
                    }
                    $filas[$n] = $datos;
                }
                break; // sólo la primera hoja
            }
        } finally {
            $lector->close();
        }

        if ($mapa === null) {
            throw AccionBloqueada::por(Motivo::de('El archivo está vacío.', 'Usa la plantilla y llena un renglón por registro.'));
        }

        return $filas;
    }

    /** @return array<int, string> índice de columna => clave */
    private function mapear(array $encabezados, $columnas, $porClave, TipoCarga $tipo): array
    {
        $mapa = [];
        foreach ($encabezados as $i => $texto) {
            $n = self::normalizar((string) $texto);
            $col = $columnas[$n] ?? $porClave[$n] ?? null;
            if ($col) {
                $mapa[$i] = $col->clave;
            }
        }
        $faltan = collect($tipo->columnas())->filter(fn (Columna $c) => $c->obligatoria && ! in_array($c->clave, $mapa, true))->pluck('encabezado');
        if ($faltan->isNotEmpty()) {
            throw AccionBloqueada::por(Motivo::de('Al archivo le faltan columnas: '.$faltan->implode(', ').'.',
                'Descarga la plantilla de «'.$tipo->getLabel().'» y copia tus datos en ella, sin cambiar el primer renglón.'));
        }

        return $mapa;
    }

    public static function normalizar(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private static function limpiar(mixed $v): mixed
    {
        return match (true) {
            $v instanceof DateTimeInterface => $v,
            is_string($v) => trim(preg_replace('/\s+/u', ' ', $v)),
            default => $v,
        };
    }

    /** Excel en español guarda los CSV con punto y coma. */
    private function delimitador(string $ruta): string
    {
        $f = fopen($ruta, 'r');
        $primera = (string) fgets($f);
        fclose($f);

        return substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    }
}
