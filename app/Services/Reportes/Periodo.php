<?php

namespace App\Services\Reportes;

use App\Support\EnLetra;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Rango de fechas de un reporte, siempre de día completo. */
final readonly class Periodo
{
    public function __construct(public CarbonInterface $desde, public CarbonInterface $hasta) {}

    public static function de(mixed $desde, mixed $hasta): self
    {
        $d = filled($desde) ? Carbon::parse($desde)->startOfDay() : Carbon::today()->startOfMonth();
        $h = filled($hasta) ? Carbon::parse($hasta)->endOfDay() : Carbon::today()->endOfDay();

        return $h->lt($d) ? new self($h->copy()->startOfDay(), $d->copy()->endOfDay()) : new self($d, $h);
    }

    public static function mes(int $anio, int $mes): self
    {
        $d = Carbon::create($anio, $mes, 1)->startOfDay();

        return new self($d, $d->copy()->endOfMonth()->endOfDay());
    }

    public static function anio(int $anio): self
    {
        return new self(Carbon::create($anio, 1, 1)->startOfDay(), Carbon::create($anio, 12, 31)->endOfDay());
    }

    public function enPalabras(): string
    {
        return 'del '.EnLetra::fecha($this->desde).' al '.EnLetra::fecha($this->hasta);
    }

    /** @return array{0: string, 1: string} */
    public function entre(): array
    {
        return [$this->desde->toDateTimeString(), $this->hasta->toDateTimeString()];
    }
}
