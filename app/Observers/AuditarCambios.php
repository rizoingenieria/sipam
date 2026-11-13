<?php

namespace App\Observers;

use App\Enums\EventoBitacora;
use App\Models\Bitacora;
use App\Support\Auditoria;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Anota en la bitácora cada alta, cambio y baja, con el antes y el después de cada campo.
 * Los campos cifrados (datos personales sensibles) se anotan como «(dato reservado)», sin su valor.
 */
class AuditarCambios
{
    public function created(Model $m): void
    {
        $this->anotar(EventoBitacora::Creado, $m, array_keys($m->getAttributes()), fn ($c) => ['despues' => $this->valor($m, $c, $m->getAttribute($c))]);
    }

    public function updated(Model $m): void
    {
        $this->anotar(EventoBitacora::Modificado, $m, array_keys($m->getChanges()), fn ($c) => [
            'antes' => $this->valor($m, $c, $m->getOriginal($c)),
            'despues' => $this->valor($m, $c, $m->getAttribute($c)),
        ], soloSiHayCambios: true);
    }

    public function deleted(Model $m): void
    {
        $this->anotar(EventoBitacora::Eliminado, $m, array_keys($m->getAttributes()), fn ($c) => ['antes' => $this->valor($m, $c, $m->getOriginal($c))]);
    }

    private function anotar(EventoBitacora $evento, Model $m, array $campos, callable $valor, bool $soloSiHayCambios = false): void
    {
        if (Auditoria::enPausa()) {
            return;
        }
        $cambios = [];
        foreach ($campos as $c) {
            if (! in_array($c, Auditoria::OMITIR, true)) {
                $cambios[$c] = $valor($c);
            }
        }
        if ($soloSiHayCambios && $cambios === []) {
            return;
        }
        Bitacora::anotar($evento, Auditoria::descripcion($m), $m, $cambios);
    }

    private function valor(Model $m, string $campo, mixed $valor): mixed
    {
        if ($m->hasCast($campo, ['encrypted', 'encrypted:array', 'encrypted:json', 'encrypted:object', 'encrypted:collection'])) {
            return $valor === null ? null : '(dato reservado)';
        }

        return match (true) {
            $valor instanceof BackedEnum => $valor->value,
            $valor instanceof DateTimeInterface => $valor->format('Y-m-d H:i:s'),
            is_string($valor) && mb_strlen($valor) > 500 => mb_substr($valor, 0, 500).'…',
            default => $valor,
        };
    }
}
