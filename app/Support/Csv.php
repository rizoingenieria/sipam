<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga en CSV que Excel abre bien a la primera: UTF-8 con BOM (acentos correctos) y coma como separador.
 */
final class Csv
{
    /**
     * @param  list<string>  $encabezados
     * @param  iterable<array<int, mixed>>  $filas
     */
    public static function descargar(string $archivo, array $encabezados, iterable $filas): StreamedResponse
    {
        return response()->streamDownload(function () use ($encabezados, $filas): void {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados, escape: '');
            foreach ($filas as $fila) {
                fputcsv($salida, array_map(fn ($v) => self::celda($v), $fila), escape: '');
            }
            fclose($salida);
        }, $archivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Evita que Excel ejecute fórmulas escondidas en un dato capturado («=HYPERLINK(...)»). */
    private static function celda(mixed $v): string
    {
        $texto = is_bool($v) ? ($v ? 'Sí' : 'No') : (string) ($v ?? '');

        return preg_match('/^[=+\-@\t\r]/', $texto) ? "'".$texto : $texto;
    }
}
