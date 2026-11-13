<?php

namespace App\Services\CargaInicial;

/**
 * Lo que pasó con cada renglón del archivo. Los errores impiden cargar; los avisos no.
 * El número de renglón es el de Excel (el encabezado es el renglón 1).
 */
final class ResultadoCarga
{
    /** @var array<int, list<string>> */
    public array $errores = [];

    /** @var array<int, list<string>> */
    public array $avisos = [];

    public int $renglones = 0;

    public int $nuevos = 0;

    public int $existentes = 0;

    public function error(int $renglon, string $mensaje): void
    {
        $this->errores[$renglon][] = $mensaje;
    }

    public function aviso(int $renglon, string $mensaje): void
    {
        $this->avisos[$renglon][] = $mensaje;
    }

    public function sinErrores(): bool
    {
        return $this->errores === [];
    }

    public function totalErrores(): int
    {
        return count($this->errores);
    }
}
