<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Cantidades y fechas escritas con letra, como las piden los documentos oficiales:
 * «MIL QUINIENTOS PESOS 00/100 M.N.» y «27 de octubre de 2026».
 * Sin depender de la extensión intl ni del idioma del servidor.
 */
final class EnLetra
{
    private const UNIDADES = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];

    private const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** 1500.5 → «MIL QUINIENTOS PESOS 50/100 M.N.» */
    public static function pesos(float|int|string $importe): string
    {
        $centavos = (int) round(((float) $importe) * 100);
        $enteros = intdiv($centavos, 100);
        $resto = $centavos % 100;
        $palabras = $enteros === 0 ? 'CERO' : self::numero($enteros);
        // «UN PESO», «VEINTIÚN PESOS», «MIL UN PESOS» (apócope ante sustantivo).
        $palabras = preg_replace(['/VEINTIUNO$/u', '/UNO$/u'], ['VEINTIÚN', 'UN'], $palabras);
        // «UN MILLÓN DE PESOS», «DOS MILLONES DE PESOS».
        $de = preg_match('/MILL(ÓN|ONES)$/u', $palabras) ? ' DE' : '';

        return $palabras.$de.' '.($enteros === 1 ? 'PESO' : 'PESOS').' '.str_pad((string) $resto, 2, '0', STR_PAD_LEFT).'/100 M.N.';
    }

    /** Entero positivo en palabras (hasta 999,999,999,999). */
    public static function numero(int $n): string
    {
        if ($n < 0) {
            return 'MENOS '.self::numero(-$n);
        }
        if ($n === 0) {
            return 'CERO';
        }

        $partes = [];
        $millones = intdiv($n, 1_000_000);
        $miles = intdiv($n % 1_000_000, 1000);
        $resto = $n % 1000;

        if ($millones > 0) {
            $partes[] = $millones === 1 ? 'UN MILLÓN' : preg_replace(['/VEINTIUNO$/u', '/UNO$/u'], ['VEINTIÚN', 'UN'], self::numero($millones)).' MILLONES';
        }
        if ($miles > 0) {
            $partes[] = $miles === 1 ? 'MIL' : preg_replace(['/VEINTIUNO$/u', '/UNO$/u'], ['VEINTIÚN', 'UN'], self::centenas($miles)).' MIL';
        }
        if ($resto > 0) {
            $partes[] = self::centenas($resto);
        }

        return implode(' ', $partes);
    }

    private static function centenas(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }
        $c = intdiv($n, 100);
        $d = $n % 100;
        $texto = self::CENTENAS[$c];
        if ($d === 0) {
            return $texto;
        }
        $decenas = $d < 30 ? self::UNIDADES[$d] : self::DECENAS[intdiv($d, 10)].($d % 10 ? ' Y '.self::UNIDADES[$d % 10] : '');

        return trim($texto.' '.$decenas);
    }

    /** «27 de octubre de 2026». */
    public static function fecha(CarbonInterface $fecha): string
    {
        return $fecha->day.' de '.self::MESES[$fecha->month - 1].' de '.$fecha->year;
    }
}
