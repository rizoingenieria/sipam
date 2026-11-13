<?php

namespace App\Services\Tramites;

use App\Enums\DestinoRestos;
use App\Enums\EstadoInhumado;
use App\Enums\EstadoPanteon;
use App\Enums\EstadoRequisitoEntregado;
use App\Enums\MotivoExhumacion;
use App\Enums\ParteTramite;
use App\Enums\TipoArchivo;
use App\Enums\TipoTramite;
use App\Models\Cesion;
use App\Models\ConceptoCobro;
use App\Models\Espacio;
use App\Models\Parametro;
use App\Models\Tramite;
use App\Models\TramiteParticipante;
use App\Services\Plazos\EvaluadorPlazoExhumacion;
use App\Services\Plazos\ResultadoPlazo;
use App\Support\Formato;
use App\Support\Motivo;
use Illuminate\Support\Carbon;

/**
 * Candados de cada trámite. Cada método devuelve la lista COMPLETA de motivos, en lenguaje llano,
 * con el paso que lo resuelve y su fundamento.
 *
 * - candados(): reglas sobre el espacio y los datos; se revisan al capturar, al revisar y al concluir,
 *   porque el estado del panteón puede cambiar entre un paso y otro.
 * - incumplimientosRevision(): además, requisitos, cotejo, pago de derechos y participantes.
 * - incumplimientosPublicacion(): además, responsiva y fotos de estrados y del lote (art. 34).
 * - incumplimientosConclusion(): además, documentos firmados, comparecencias y pagos.
 */
final class ValidadorTramite
{
    /** Qué hacer con un documento que falta; la interfaz lo usa para agrupar esos pendientes. */
    public const RECIBIR_DOCUMENTO = 'Recíbelo y márcalo como entregado.';

    public function __construct(private readonly EvaluadorPlazoExhumacion $evaluador) {}

    /** @return list<Motivo> */
    public function candados(Tramite $t, ?Carbon $fecha = null): array
    {
        $fecha ??= Carbon::today();
        $espacio = Espacio::with(['tituloVigente.regimen', 'lote.seccion.panteon', 'ultimoInhumado'])->findOrFail($t->espacio_id);
        $panteon = $espacio->lote->seccion->panteon;
        $titulo = $espacio->tituloVigente;
        $m = [];

        if (! $espacio->habilitado) {
            $m[] = Motivo::de('El espacio está marcado como no habilitado.', 'Revisa con el Encargado de Panteones si es el espacio correcto.');
        }

        switch ($t->tipo) {
            case TipoTramite::Adquisicion:
                if ($titulo !== null) {
                    $m[] = Motivo::de("Este espacio ya tiene título vigente ({$titulo->folio}).",
                        'Si cambia el dueño, captura una regularización o cambio de propietario, no una adquisición.', 'Art. 33');
                }
                break;

            case TipoTramite::Regularizacion:
                if ($titulo === null) {
                    $m[] = Motivo::de('Este espacio no tiene título vigente que transmitir.',
                        'Si la persona sólo tiene la posesión, captura una adquisición.', 'Art. 27, fr. I');
                } elseif ($t->dato('via') === 'cesion' && ! $titulo->regimen->permite_cesion) {
                    $m[] = Motivo::de("El título es de «{$titulo->regimen->nombre}», que no permite ceder el derecho.",
                        'Si se trata de una herencia, cambia la vía del trámite a herencia o legado.', 'Art. 27');
                }
                break;

            case TipoTramite::Inhumacion:
                if ($panteon->estado === EstadoPanteon::Clausurado) {
                    $m[] = Motivo::de('El panteón está clausurado y ya no recibe inhumaciones.', 'Elige un espacio en otro panteón.', 'Art. 105');
                }
                if (! $espacio->tipo->admiteCadaver()) {
                    $m[] = Motivo::de('Un nicho sólo recibe restos áridos o cenizas, no un cuerpo.', 'Elige una fosa, cripta o gaveta.', 'Art. 32');
                }
                if ($panteon->estado === EstadoPanteon::Saturado && ! $espacio->es_fosa_comun && $titulo === null) {
                    $m[] = Motivo::de('El panteón está saturado: sólo se inhuma en fosas que ya tienen título.', 'Elige una fosa con título o la fosa común.', 'Art. 105');
                }
                $ultimo = $espacio->ultimoInhumado;
                if (! $espacio->es_fosa_comun && $ultimo !== null && $ultimo->estado === EstadoInhumado::Inhumado) {
                    $resultado = $this->evaluador->evaluar($ultimo, MotivoExhumacion::ReutilizacionFosa, $fecha);
                    $this->anotarPlazo($t, $resultado);
                    if (! $resultado->permitido) {
                        $m[] = Motivo::de(
                            "La fosa no puede abrirse todavía: ahí está sepultado {$ultimo->nombre_completo}. ".$resultado->motivo->problema,
                            $resultado->motivo->queHacer.' Mientras, elige otra fosa.',
                            $resultado->motivo->fundamento,
                        );
                    }
                }
                break;

            case TipoTramite::Exhumacion:
                $inhumado = $t->inhumado;
                if ($inhumado === null || (int) $inhumado->espacio_id !== (int) $espacio->id) {
                    $m[] = Motivo::de('La persona a exhumar no está registrada en este espacio.', 'Revisa el espacio o busca a la persona en el registro de finados.');
                } elseif ($inhumado->estado !== EstadoInhumado::Inhumado) {
                    $m[] = Motivo::de("Los restos de {$inhumado->nombre_completo} ya fueron exhumados.", 'Revisa su historial en el registro de finados.');
                } else {
                    $resultado = $this->evaluador->evaluar($inhumado, $t->motivo_exhumacion, $fecha);
                    $this->anotarPlazo($t, $resultado);
                    if (! $resultado->permitido) {
                        $m[] = $resultado->motivo;
                    }
                }
                if ($t->motivo_exhumacion === MotivoExhumacion::Vencimiento && $titulo !== null && ! $titulo->estaVencidoA($fecha)) {
                    $m[] = Motivo::de('El derecho de uso todavía no vence: el título vence el '.Formato::fecha($titulo->fecha_vencimiento).'.',
                        'Si la familia quiere llevarse los restos, cambia el motivo a traslado.', 'Art. 41, fr. II');
                }
                if ($t->dato('destino.tipo') === DestinoRestos::OtroEspacio->value) {
                    $destino = Espacio::find($t->dato('destino.espacio_destino_id'));
                    if ($destino === null || (int) $destino->id === (int) $espacio->id || ! $destino->habilitado) {
                        $m[] = Motivo::de('El espacio a donde irán los restos no es válido.', 'Elige otro espacio habilitado, distinto del de origen.');
                    }
                }
                break;

            case TipoTramite::Construccion:
                if ($titulo === null) {
                    $m[] = Motivo::de('Para construir, el espacio debe tener título vigente.', 'Primero hay que tramitar la adquisición o regularización del espacio.');
                }
                $m = [...$m, ...$this->dimensionesObra($t, $espacio)];
                if ($espacio->es_monumento_historico && blank($t->dato('permiso_inah_folio'))) {
                    $m[] = Motivo::de('Es un monumento histórico: la obra necesita permiso del INAH.', 'Captura el folio del permiso del INAH.', 'Art. 89');
                }
                break;
        }

        return $m;
    }

    /** @return list<Motivo> */
    public function incumplimientosRevision(Tramite $t): array
    {
        $m = [...$this->candados($t), ...$this->requisitosFaltantes($t)];

        if ($t->cotejo_en === null) {
            $m[] = Motivo::de('Falta el cotejo del expediente con el titular presente.',
                'Revisa en físico el espacio y compara los documentos con los registros; después márcalo como hecho.', 'Art. 21, fr. XXIX');
        }

        $exigePagoDerechos = $t->requisitos()->whereHas('requisito', fn ($q) => $q->where('activa_pago_derechos', true))->exists();
        if ($exigePagoDerechos && ! $t->pago_derechos_al_corriente) {
            $m[] = Motivo::de('Falta el comprobante de que los derechos de uso están pagados.',
                'Pide al solicitante el comprobante que expide Tesorería y captura su folio.', 'Arts. 27, fr. II, y 33, fr. II');
        }

        return [...$m, ...$this->participantes($t)];
    }

    /** @return list<Motivo> */
    public function incumplimientosPublicacion(Tramite $t): array
    {
        $m = $this->incumplimientosRevision($t);

        if ($this->emitidosPendientes($t) !== []) {
            $m[] = Motivo::de('La notificación se publica después de firmar la responsiva del Encargado de Panteones.', 'Sube la responsiva firmada.', 'Art. 34');
        }
        foreach ([TipoArchivo::FotoEstrados, TipoArchivo::FotoLote] as $tipo) {
            if ($t->archivos()->where('tipo', $tipo->value)->doesntExist()) {
                $m[] = Motivo::de("Falta la {$this->enMinuscula($tipo->getLabel())}.", 'Toma la foto con la notificación ya colocada y súbela al trámite.', 'Art. 34');
            }
        }

        return $m;
    }

    /** @return list<Motivo> */
    public function incumplimientosConclusion(Tramite $t): array
    {
        $m = [...$this->candados($t), ...$this->emitidosPendientes($t)];

        if (in_array($t->tipo, [TipoTramite::Adquisicion, TipoTramite::Regularizacion], true)) {
            foreach ($t->participantesCon(ParteTramite::NuevoTitular) as $p) {
                if (! $p->comparecio()) {
                    $m[] = Motivo::de("{$p->persona->nombre_completo} no ha venido a firmar el título.",
                        'Debe presentarse en persona; no se acepta carta poder. Marca su comparecencia cuando firme.');
                }
            }
        }

        if (! $t->por_orden_autoridad && ! $t->todoPagado()) {
            $m[] = Motivo::de('Hay órdenes de pago sin pagar.', 'Captura el folio del recibo que entrega Tesorería en cada orden.');
        }

        return $m;
    }

    /** @return list<Motivo> */
    private function emitidosPendientes(Tramite $t): array
    {
        return $t->requisitos()->with('requisito')->get()
            ->filter(fn ($f) => $f->requisito->emitido_por_sistema && $f->estado !== EstadoRequisitoEntregado::Entregado)
            ->map(fn ($f) => Motivo::de("Falta firmar: {$this->enMinuscula($f->requisito->documento)}.", 'Imprímela, recaba la firma y sube el documento firmado.', $f->requisito->fundamento))
            ->values()->all();
    }

    /** @return list<Motivo> */
    private function requisitosFaltantes(Tramite $t): array
    {
        $filas = $t->requisitos()->with('requisito')->get()
            ->filter(fn ($f) => $f->requisito->obligatorio && ! $f->requisito->emitido_por_sistema);

        $grupos = $filas->groupBy(fn ($f) => $f->requisito->grupo_alternativa !== null
            ? 'g:'.$f->requisito->grupo_alternativa
            : 'f:'.$f->id);

        $m = [];
        foreach ($grupos as $grupo) {
            $cumplido = $grupo->contains(fn ($f) => in_array($f->estado, [EstadoRequisitoEntregado::Entregado, EstadoRequisitoEntregado::NoAplica], true));
            if ($cumplido) {
                continue;
            }
            $primera = $grupo->first();
            $documentos = $grupo->map(fn ($f) => $f->requisito->documento)->unique()->implode(' o ');
            $varias = $grupo->count() === 1 && $filas->where('requisito_id', $primera->requisito_id)->count() > 1;
            $quien = $primera->requisito->de_quien->getLabel().($varias ? " {$primera->ordinal}" : '');
            $m[] = Motivo::de("Falta: {$documentos} ({$quien}).", self::RECIBIR_DOCUMENTO, $primera->requisito->fundamento);
        }

        return $m;
    }

    /** @return list<Motivo> */
    private function participantes(Tramite $t): array
    {
        $m = [];
        $porPapel = fn (ParteTramite $p) => $t->participantesCon($p);

        if (in_array($t->tipo, [TipoTramite::Adquisicion, TipoTramite::Regularizacion], true)) {
            $minimo = Parametro::entero('testigos_requeridos');
            $anios = Parametro::entero('residencia_minima_testigos_anios');
            $validos = $porPapel(ParteTramite::Testigo)->filter(fn (TramiteParticipante $p) => ($p->anios_residencia ?? 0) >= $anios);
            if ($validos->count() < $minimo) {
                $m[] = Motivo::de(
                    'Faltan testigos: se piden '.Formato::cantidad($minimo, 'testigo').' con al menos '.Formato::cantidad($anios, 'año').' viviendo en el municipio, y hay '.$validos->count().'.',
                    'Registra a cada testigo con sus años de residencia.', 'Arts. 27, fr. IV, y 33, fr. V');
            }

            $nuevos = $porPapel(ParteTramite::NuevoTitular);
            if ($nuevos->isEmpty()) {
                $m[] = Motivo::de('No se ha indicado a nombre de quién quedará el título.', 'Registra a la persona o personas que serán titulares.');
            }
            if ($t->tipo === TipoTramite::Regularizacion && $t->dato('via') === 'cesion') {
                foreach ($nuevos as $p) {
                    if (Cesion::where('espacio_id', $t->espacio_id)->where('cesionario_id', $p->persona_id)->exists()) {
                        $m[] = Motivo::de("{$p->persona->nombre_completo} ya recibió antes este espacio por cesión, y sólo puede cedérsele una vez.",
                            'El trámite no procede para esta persona.', 'Art. 27, fr. VI');
                    }
                }
            }
        }

        if (in_array($t->tipo, [TipoTramite::Inhumacion, TipoTramite::Exhumacion], true) && ! $t->por_orden_autoridad) {
            $m = [...$m, ...$this->autorizante($t)];
        }

        if ($t->tipo === TipoTramite::Construccion && $porPapel(ParteTramite::Constructor)->isEmpty()) {
            $m[] = Motivo::de('No se ha registrado a la persona que hará la obra.', 'Regístrala con su identificación y domicilio.', 'Art. 52, fr. III');
        }

        return $m;
    }

    /**
     * Autoriza el titular o, si falleció, un familiar directo que se presente en persona (hojas de requisitos).
     *
     * @return list<Motivo>
     */
    private function autorizante(Tramite $t): array
    {
        $autorizantes = $t->participantesCon(ParteTramite::Autorizante);
        if ($autorizantes->isEmpty()) {
            return [Motivo::de('No se ha registrado quién da la autorización.', 'Registra al titular o, si falleció, a su familiar más directo.')];
        }

        $titulares = array_map('intval', $t->espacio->tituloVigente?->titulares()->pluck('personas.id')->all() ?? []);
        $m = [];
        foreach ($autorizantes as $p) {
            $legitimado = in_array((int) $p->persona_id, $titulares, true) || ($p->parentesco?->puede_autorizar ?? false);
            if (! $legitimado) {
                $m[] = Motivo::de("{$p->persona->nombre_completo} no es titular del espacio ni tiene registrado un parentesco con el finado.",
                    'Si es familiar, indica su parentesco; si no, debe autorizar el titular.');
            }
            if (! $p->comparecio()) {
                $m[] = Motivo::de("{$p->persona->nombre_completo} no ha venido a firmar su autorización.", 'Debe presentarse en persona; marca su comparecencia cuando firme.');
            }
        }

        return $m;
    }

    /** Art. 56: el monumento no excede la superficie de la fosa; altura máxima y altura total con cruz o estatua. */
    private function dimensionesObra(Tramite $t, Espacio $espacio): array
    {
        $largoMax = min((float) ($espacio->largo_m ?? PHP_FLOAT_MAX), Parametro::decimal('monumento_largo_maximo_m'));
        $anchoMax = min((float) ($espacio->ancho_m ?? PHP_FLOAT_MAX), Parametro::decimal('monumento_ancho_maximo_m'));
        $alturaMax = Parametro::decimal('monumento_altura_maxima_m');
        $totalMax = Parametro::decimal('monumento_altura_total_maxima_m');
        $m = [];
        $metros = fn (float $v) => Formato::numero($v, 2).' m';

        foreach ([
            ['largo_m', $largoMax, 'El largo de la obra pasa de la medida de la fosa'],
            ['ancho_m', $anchoMax, 'El ancho de la obra pasa de la medida de la fosa'],
            ['altura_m', $alturaMax, 'El monumento es más alto de lo permitido'],
            ['altura_total_m', $totalMax, 'La altura total con cruz o estatua es mayor a la permitida'],
        ] as [$campo, $maximo, $texto]) {
            $valor = $t->dato($campo);
            if ($valor !== null && (float) $valor > $maximo) {
                $m[] = Motivo::de("{$texto}: mide {$metros((float) $valor)} y el máximo es {$metros($maximo)}.", 'Pide al solicitante que ajuste el croquis.', 'Art. 56');
            }
        }

        $concepto = ConceptoCobro::where('clave', $t->dato('concepto_clave'))->first();
        if ($concepto !== null && ! $concepto->activo) {
            $m[] = Motivo::de("El tipo de obra «{$concepto->nombre}» ya no se ofrece.", 'Elige otro tipo de obra.');
        }

        return $m;
    }

    private function anotarPlazo(Tramite $t, ResultadoPlazo $resultado): void
    {
        $t->evaluacion_plazo = $resultado->paraResolucion() + ['evaluado_en' => Carbon::now()->toDateTimeString()];
    }

    private function enMinuscula(string $texto): string
    {
        return mb_strtolower(mb_substr($texto, 0, 1)).mb_substr($texto, 1);
    }
}
