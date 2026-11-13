<?php

namespace App\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Catálogo versionado por vigencia (vigente_desde / vigente_hasta).
 *
 * Reglas:
 * - Lo que decide (importes, plazos, edades, banderas) no se edita en una versión que ya entró en vigor:
 *   se registra una versión nueva con nuevaVersion(), que cierra la actual el día anterior.
 * - Lo que sólo describe (nombre, descripción, fundamento, redacción) sí se corrige en vigor:
 *   arreglar una errata no cambia ninguna decisión. Cada modelo lo declara en $editablesEnVigor.
 * - Una versión con vigencia futura se corrige completa, porque nada la ha usado.
 * - La unicidad de la versión abierta la garantiza la base de datos.
 *
 * El modelo declara sus columnas generadas en $columnasGeneradas para excluirlas al replicar.
 */
trait Versionable
{
    public static function bootVersionable(): void
    {
        static::updating(function (self $modelo): void {
            $permitidos = ['vigente_hasta', 'updated_at', ...$modelo->camposEditablesEnVigor()];
            $cambios = array_values(array_diff(array_keys($modelo->getDirty()), $permitidos));

            if ($cambios !== [] && $modelo->entroEnVigor()) {
                throw new LogicException(
                    'Esta versión ya está en vigor y no se edita ('.implode(', ', $cambios).'). Registra una versión nueva con la fecha en que debe aplicar.'
                );
            }
        });

        static::deleting(function (self $modelo): void {
            if ($modelo->entroEnVigor() && ! $modelo->deshaciendo) {
                throw new LogicException('Una versión que ya entró en vigor no se borra; ciérrala con una fecha de fin.');
            }
        });
    }

    /** Sólo deshacer() puede borrar una versión que entró en vigor hoy. */
    protected bool $deshaciendo = false;

    /** Columnas que identifican al «mismo» registro a través de sus versiones. */
    public function llaveVersion(): array
    {
        return property_exists($this, 'llaveVersion') ? $this->llaveVersion : ['clave'];
    }

    /**
     * ¿Algo ya usó esta versión (una orden de pago, un trámite, un título)? Cada modelo lo responde;
     * si no puede saberlo, se asume que no. Sólo se consulta para deshacer un cambio del mismo día.
     */
    public function estaEnUso(): bool
    {
        return false;
    }

    /** Todas las versiones del mismo registro, de la más reciente a la más antigua. */
    public function historial(): Collection
    {
        return static::query()->where($this->only($this->llaveVersion()))->orderByDesc('vigente_desde')->get();
    }

    /** La versión que cerró ésta, si la hay. */
    public function versionAnterior(): ?static
    {
        return static::query()->where($this->only($this->llaveVersion()))
            ->whereDate('vigente_hasta', $this->vigente_desde->copy()->subDay())->first();
    }

    /**
     * Un cambio se puede deshacer el mismo día (o antes de que empiece), si nada lo ha usado.
     * Es la red para quien se equivocó al capturar: sin ella, el error de hoy viviría hasta mañana.
     */
    public function puedeDeshacerse(): bool
    {
        return $this->exists && $this->vigente_hasta === null
            && $this->vigente_desde->gte(Carbon::today())
            && ! $this->estaEnUso();
    }

    /** Borra esta versión y vuelve a abrir la anterior, si la había. */
    public function deshacer(): ?static
    {
        if (! $this->puedeDeshacerse()) {
            throw new LogicException('Este cambio ya no se puede deshacer: empezó antes de hoy o ya se usó. Registra una versión nueva.');
        }

        return DB::transaction(function (): ?static {
            $anterior = $this->versionAnterior();
            $this->deshaciendo = true;
            $this->delete();
            $anterior?->update(['vigente_hasta' => null]);

            return $anterior;
        });
    }

    public function initializeVersionable(): void
    {
        $this->mergeCasts(['vigente_desde' => 'date', 'vigente_hasta' => 'date']);
    }

    /** @return list<string> */
    public function camposEditablesEnVigor(): array
    {
        return property_exists($this, 'editablesEnVigor') ? $this->editablesEnVigor : [];
    }

    /** Versiones aplicables en una fecha (hoy por omisión). */
    public function scopeVigenteEn(Builder $query, ?CarbonInterface $fecha = null): Builder
    {
        $dia = ($fecha ?? Carbon::today())->toDateString();
        $tabla = $this->getTable();

        return $query->where("{$tabla}.vigente_desde", '<=', $dia)
            ->where(fn (Builder $q) => $q->whereNull("{$tabla}.vigente_hasta")->orWhere("{$tabla}.vigente_hasta", '>=', $dia));
    }

    /** La versión más reciente de cada registro, esté abierta o cerrada. */
    public function scopeUltimas(Builder $query): Builder
    {
        $tabla = $this->getTable();

        return $query->whereNotExists(function ($sub) use ($tabla): void {
            $sub->selectRaw('1')->from("{$tabla} as posterior")
                ->whereColumn('posterior.vigente_desde', '>', "{$tabla}.vigente_desde");
            foreach ($this->llaveVersion() as $columna) {
                $sub->whereColumn("posterior.{$columna}", "{$tabla}.{$columna}");
            }
        });
    }

    /** Las que aplican hoy o aplicarán: la última versión, sin cerrar o cerrada en el futuro. */
    public function scopeQueAplican(Builder $query): Builder
    {
        $tabla = $this->getTable();

        return $query->ultimas()->where(fn (Builder $q) => $q->whereNull("{$tabla}.vigente_hasta")
            ->orWhere("{$tabla}.vigente_hasta", '>=', Carbon::today()->toDateString()));
    }

    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->whereNull($this->getTable().'.vigente_hasta');
    }

    public function entroEnVigor(): bool
    {
        $desde = $this->getOriginal('vigente_desde') ?? $this->vigente_desde;

        return $desde !== null && Carbon::parse($desde)->lte(Carbon::today());
    }

    /**
     * Cierra esta versión el día anterior a $desde y abre una nueva con $cambios.
     */
    public function nuevaVersion(array $cambios, CarbonInterface $desde): static
    {
        if ($this->vigente_hasta !== null) {
            throw new LogicException('Sólo la versión vigente puede reemplazarse.');
        }
        if ($desde->lte($this->vigente_desde)) {
            throw new InvalidArgumentException('La versión nueva debe empezar después del '.$this->vigente_desde->format('d/m/Y').'.');
        }

        return DB::transaction(function () use ($cambios, $desde): static {
            $this->vigente_hasta = $desde->copy()->subDay();
            $this->save();

            $nueva = $this->replicate($this->columnasGeneradas ?? []);
            $nueva->fill($cambios);
            $nueva->vigente_desde = $desde;
            $nueva->vigente_hasta = null;
            $nueva->save();

            return $nueva;
        });
    }
}
