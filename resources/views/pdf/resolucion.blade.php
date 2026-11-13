@extends('pdf.layout')
@section('contenido')
<p style="font-size:13pt;font-weight:bold;margin:0 0 10pt">{{ mb_strtoupper($encabezado) }}</p>

<table class="datos">
    <tr><td class="k">Expediente</td><td>{{ $t->folio }} · {{ $t->tipo->getLabel() }}</td></tr>
    <tr><td class="k">Solicitante</td><td>{{ $t->solicitante->nombre_completo }}</td></tr>
    @if ($t->tipo === \App\Enums\TipoTramite::Inhumacion)
        <tr><td class="k">Persona inhumada</td><td>{{ trim($t->dato('finado.nombre').' '.$t->dato('finado.primer_apellido').' '.$t->dato('finado.segundo_apellido')) }}</td></tr>
        <tr><td class="k">Acta de defunción</td><td>{{ $t->dato('finado.numero_acta_defuncion') }}</td></tr>
        <tr><td class="k">Permiso para sepultar</td><td>{{ $t->dato('permiso_folio') }}</td></tr>
    @elseif ($t->tipo === \App\Enums\TipoTramite::Exhumacion)
        <tr><td class="k">Persona exhumada</td><td>{{ $t->inhumado?->nombre_completo }}</td></tr>
        <tr><td class="k">Motivo</td><td>{{ $t->motivo_exhumacion?->getLabel() }}</td></tr>
        <tr><td class="k">Permiso de exhumación</td><td>{{ $t->dato('permiso_folio') }}</td></tr>
        @if (! empty($t->evaluacion_plazo['explicacion']))
            <tr><td class="k">Plazo</td><td>{{ $t->evaluacion_plazo['explicacion'] }}</td></tr>
        @endif
    @elseif ($t->tipo === \App\Enums\TipoTramite::Construccion)
        <tr><td class="k">Obra</td><td>{{ \App\Models\ConceptoCobro::where('clave', $t->dato('concepto_clave'))->value('nombre') }}: {{ $t->dato('descripcion') }}</td></tr>
        <tr><td class="k">Plazo de ejecución</td><td>{{ $t->dato('tiempo_estimado_dias') }} días</td></tr>
        <tr><td class="k">Permiso de Obras Públicas</td><td>{{ $t->dato('permiso_obras_folio') }}</td></tr>
    @endif
</table>

<h2>Espacio</h2>
@include('pdf.partes.espacio', ['espacio' => $t->espacio])

<h2>{{ $concluido ? 'Resolución' : 'Motivo' }}</h2>
<p class="grande">{{ $t->resolucion }}</p>
@if (! $concluido)
    <p style="font-size:8.6pt">Contra esta resolución procede el recurso previsto en el Código de Procedimientos Administrativos para el Estado de Veracruz de Ignacio de la Llave.</p>
@endif

@include('pdf.partes.firmas', ['firmas' => $firmas])
@endsection
