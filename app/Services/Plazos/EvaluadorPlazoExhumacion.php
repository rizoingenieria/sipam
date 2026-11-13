<?php

namespace App\Services\Plazos;

use App\Enums\EstadoPanteon;
use App\Enums\MotivoExhumacion;
use App\Models\Inhumado;
use App\Models\ReglaPlazo;
use App\Support\Formato;
use App\Support\Motivo;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decide si un inhumado puede exhumarse en una fecha, con las reglas de plazo vigentes en esa fecha.
 *
 * - Aplica toda regla cuyo motivo, régimen, rango de edad y condición de saturación coincidan.
 * - Si aplican varias, manda la que da la fecha más tardía.
 * - Ante un dato faltante que una regla necesita (fecha de inhumación, edad), niega y dice cómo resolverlo.
 * - Si ninguna regla aplica, niega: un catálogo incompleto no debe autorizar por omisión.
 * - La orden de autoridad no se sujeta a plazo (arts. 40 y 42).
 */
final class EvaluadorPlazoExhumacion
{
    public function evaluar(Inhumado $inhumado, MotivoExhumacion $motivo, ?CarbonInterface $fecha = null): ResultadoPlazo
    {
        $fecha = ($fecha ?? Carbon::today())->copy()->startOfDay();

        if (! $motivo->sujetoAPlazo()) {
            return new ResultadoPlazo(true, null, null, collect(),
                'Exhumación por orden de autoridad: no se sujeta a plazo (arts. 40 y 42).');
        }

        if ($inhumado->fecha_inhumacion === null) {
            return $this->negar(Motivo::de(
                'Falta la fecha de inhumación del finado; sin ella no se puede saber si ya se cumplió el plazo.',
                'Captúrala en el registro del finado. Si no se conoce, búscala en el libro de inhumaciones del panteón.',
            ));
        }

        $espacio = $inhumado->espacio()->with(['tituloVigente.regimen', 'lote.seccion.panteon'])->first();
        $regimenClave = $espacio?->tituloVigente?->regimen?->clave;
        $saturado = $espacio?->lote?->seccion?->panteon?->estado === EstadoPanteon::Saturado;

        $candidatas = ReglaPlazo::query()->vigenteEn($fecha)->get()->filter(
            fn (ReglaPlazo $r): bool => ($r->motivo === null || $r->motivo === $motivo)
                && ($r->regimen_clave === null || $r->regimen_clave === $regimenClave)
                && (! $r->solo_panteon_saturado || $saturado)
        );

        if ($inhumado->edad_al_fallecer === null && $candidatas->contains(fn (ReglaPlazo $r) => $r->dependeDeEdad())) {
            return $this->negar(Motivo::de(
                'Falta la edad al fallecer del finado; el plazo para exhumar depende de ella.',
                'Captúrala en el registro del finado. Viene en el acta de defunción.',
            ));
        }

        $aplicadas = $candidatas
            ->filter(fn (ReglaPlazo $r): bool => ! $r->dependeDeEdad() || $r->cubreEdad($inhumado->edad_al_fallecer))
            ->values();

        if ($aplicadas->isEmpty()) {
            return $this->negar(Motivo::de(
                'Ninguna regla de plazo vigente aplica a este caso, así que el sistema no puede autorizarlo.',
                'Pide al administrador del sistema que revise las reglas de plazo para exhumar.',
            ));
        }

        /** @var ReglaPlazo $determinante */
        $determinante = $aplicadas->sortByDesc(fn (ReglaPlazo $r) => $r->fechaPermitida($inhumado->fecha_inhumacion)->getTimestamp())->first();
        $fechaPermitida = $determinante->fechaPermitida($inhumado->fecha_inhumacion);
        $permitido = $fecha->gte($fechaPermitida);

        $plazo = mb_strtolower($determinante->comparacion->getLabel()).' '.Formato::cantidad($determinante->anios_minimos, 'año');
        $explicacion = sprintf(
            '%s El plazo es de %s desde la inhumación (%s). Fue inhumado el %s; puede exhumarse desde el %s.',
            $permitido ? 'Cumple el plazo.' : 'No cumple el plazo.',
            $plazo,
            $determinante->fundamento,
            Formato::fecha($inhumado->fecha_inhumacion),
            Formato::fecha($fechaPermitida),
        );

        $motivoNegativa = $permitido ? null : Motivo::de(
            "Todavía no se cumple el plazo para exhumar: deben pasar {$plazo} desde la inhumación del ".Formato::fecha($inhumado->fecha_inhumacion).'.',
            'Podrá hacerse a partir del '.Formato::fecha($fechaPermitida).'.',
            $determinante->fundamento,
        );

        return new ResultadoPlazo($permitido, $fechaPermitida, $determinante, $aplicadas, $explicacion, $motivoNegativa);
    }

    private function negar(Motivo $motivo): ResultadoPlazo
    {
        return new ResultadoPlazo(false, null, null, new Collection, (string) $motivo, $motivo);
    }
}
