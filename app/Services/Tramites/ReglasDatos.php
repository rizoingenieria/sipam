<?php

namespace App\Services\Tramites;

use App\Enums\DestinoRestos;
use App\Enums\Sexo;
use App\Enums\TipoTramite;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Qué datos propios exige cada tipo de trámite al capturarse.
 * Lanza Illuminate\Validation\ValidationException, que Filament muestra campo por campo.
 */
final class ReglasDatos
{
    public static function validar(TipoTramite $tipo, array $datos, ?string $causaFallecimiento = null): array
    {
        $entrada = $datos + ($tipo === TipoTramite::Inhumacion ? ['causa_fallecimiento' => $causaFallecimiento] : []);

        $validos = Validator::make($entrada, self::reglas($tipo), self::mensajes(), self::atributos())->validate();
        unset($validos['causa_fallecimiento']);

        return $validos;
    }

    public static function reglas(TipoTramite $tipo): array
    {
        return match ($tipo) {
            // Art. 33, fr. I: el escrito señala el tiempo de posesión.
            TipoTramite::Adquisicion => [
                'tiempo_posesion' => ['required', 'string', 'max:100'],
                'regimen_clave' => ['nullable', 'string', Rule::exists('regimenes', 'clave')],
            ],
            // Art. 27 (cesión o donación) o art. 67 (herencia o legado).
            TipoTramite::Regularizacion => [
                'via' => ['required', Rule::in(['cesion', 'herencia'])],
            ],
            // Art. 21, fr. III, inciso a): datos del libro de inhumaciones.
            TipoTramite::Inhumacion => [
                'finado' => ['required', 'array'],
                'finado.nombre' => ['required', 'string', 'max:100'],
                'finado.primer_apellido' => ['nullable', 'string', 'max:100'],
                'finado.segundo_apellido' => ['nullable', 'string', 'max:100'],
                'finado.es_desconocido' => ['boolean'],
                'finado.sexo' => ['required', Rule::enum(Sexo::class)],
                'finado.fecha_nacimiento' => ['nullable', 'date'],
                'finado.fecha_defuncion' => ['required', 'date', 'before_or_equal:today'],
                // La edad es la base de los plazos de exhumación: o la edad o la fecha de nacimiento.
                'finado.edad_al_fallecer' => ['nullable', 'required_without:finado.fecha_nacimiento', 'integer', 'between:0,130'],
                'finado.numero_acta_defuncion' => ['required', 'string', 'max:50'],
                'finado.lugar_fallecimiento' => ['required', 'string', 'max:255'],
                'causa_fallecimiento' => ['required', 'string', 'max:500'],
                'permiso_folio' => ['required', 'string', 'max:50'],
                'permiso_fecha' => ['required', 'date', 'before_or_equal:today'],
            ],
            // Art. 21, fr. III, incisos b) y c); art. 42.
            TipoTramite::Exhumacion => [
                'destino' => ['required', 'array'],
                'destino.tipo' => ['required', Rule::enum(DestinoRestos::class)],
                'destino.destino_externo' => ['nullable', 'required_if:destino.tipo,traslado', 'string', 'max:255'],
                'destino.realizado_por' => ['nullable', 'required_if:destino.tipo,traslado', 'string', 'max:150'],
                'destino.espacio_destino_id' => ['nullable', 'required_if:destino.tipo,otro_espacio', 'integer', Rule::exists('espacios', 'id')],
                'permiso_folio' => ['required', 'string', 'max:50'],
                'permiso_fecha' => ['required', 'date', 'before_or_equal:today'],
            ],
            // Art. 52, fr. I y II; art. 56 (dimensiones); art. 89 (INAH).
            TipoTramite::Construccion => [
                'concepto_clave' => ['required', 'string', Rule::exists('conceptos_cobro', 'clave')->where('categoria', 'obra')],
                'descripcion' => ['required', 'string', 'max:500'],
                'materiales' => ['required', 'string', 'max:500'],
                'tiempo_estimado_dias' => ['required', 'integer', 'min:1', 'max:365'],
                'largo_m' => ['nullable', 'numeric', 'gt:0'],
                'ancho_m' => ['nullable', 'numeric', 'gt:0'],
                'altura_m' => ['nullable', 'numeric', 'gt:0'],
                'altura_total_m' => ['nullable', 'numeric', 'gt:0'],
                'permiso_obras_folio' => ['required', 'string', 'max:50'],
                'permiso_inah_folio' => ['nullable', 'string', 'max:50'],
            ],
        };
    }

    /** Todas las fechas «hasta hoy» de estos trámites: decirlo así, no «debe ser el today». */
    private static function mensajes(): array
    {
        return ['before_or_equal' => ':Attribute no puede ser una fecha futura.'];
    }

    private static function atributos(): array
    {
        return [
            'tiempo_posesion' => 'el tiempo de posesión',
            'regimen_clave' => 'el régimen',
            'via' => 'la vía (cesión o herencia)',
            'finado' => 'los datos del finado',
            'finado.nombre' => 'el nombre del finado',
            'finado.primer_apellido' => 'el primer apellido del finado',
            'finado.segundo_apellido' => 'el segundo apellido del finado',
            'finado.sexo' => 'el sexo del finado',
            'finado.fecha_nacimiento' => 'la fecha de nacimiento del finado',
            'finado.fecha_defuncion' => 'la fecha de defunción',
            'finado.edad_al_fallecer' => 'la edad al fallecer',
            'finado.numero_acta_defuncion' => 'el número de acta de defunción',
            'finado.lugar_fallecimiento' => 'el lugar de fallecimiento',
            'causa_fallecimiento' => 'la causa de fallecimiento',
            'permiso_folio' => 'el folio del permiso',
            'permiso_fecha' => 'la fecha del permiso',
            'destino' => 'el destino de los restos',
            'destino.tipo' => 'el destino de los restos',
            'destino.destino_externo' => 'el lugar a donde se trasladan los restos',
            'destino.realizado_por' => 'quién hace el traslado',
            'destino.espacio_destino_id' => 'el espacio a donde irán los restos',
            'concepto_clave' => 'el tipo de obra',
            'descripcion' => 'la descripción de la obra',
            'materiales' => 'los materiales',
            'tiempo_estimado_dias' => 'el tiempo estimado de la obra',
            'largo_m' => 'el largo',
            'ancho_m' => 'el ancho',
            'altura_m' => 'la altura del monumento',
            'altura_total_m' => 'la altura total',
            'permiso_obras_folio' => 'el folio del permiso de Obras Públicas',
            'permiso_inah_folio' => 'el folio del permiso del INAH',
        ];
    }
}
