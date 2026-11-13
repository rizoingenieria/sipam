<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Formatos de la guía «Papel y tinta» (sección 10): «3 oct 2026», «$1,500.00», «1,284».
 * Sin depender del idioma del servidor, para que los mensajes y los PDF salgan iguales en cualquier equipo.
 */
final class Formato
{
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public static function fecha(?CarbonInterface $fecha): string
    {
        if ($fecha === null) {
            return 'sin fecha';
        }

        return $fecha->day.' '.self::MESES[$fecha->month - 1].' '.$fecha->year;
    }

    public static function dinero(float|int|string $importe): string
    {
        return '$'.number_format((float) $importe, 2, '.', ',');
    }

    public static function numero(float|int|string $valor, int $decimales = 0): string
    {
        return number_format((float) $valor, $decimales, '.', ',');
    }

    /** «1 año», «7 años». */
    public static function cantidad(int $n, string $singular, ?string $plural = null): string
    {
        return $n.' '.($n === 1 ? $singular : ($plural ?? $singular.'s'));
    }
}
