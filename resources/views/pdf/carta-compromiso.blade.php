@extends('pdf.layout')
@section('contenido')
<p>Quien suscribe, <strong>{{ $constructor?->nombre_completo ?? '______________________________' }}</strong>,
responsable de la obra de <strong>{{ mb_strtolower(\App\Models\ConceptoCobro::where('clave', $t->dato('concepto_clave'))->value('nombre') ?? 'construcción') }}</strong>
solicitada por {{ $t->solicitante->nombre_completo }} (expediente {{ $t->folio }}) en el espacio:</p>

@include('pdf.partes.espacio', ['espacio' => $t->espacio])

<h2>Me comprometo a</h2>
<p>1. No dañar ni afectar las fosas, monumentos y construcciones contiguas; en caso de hacerlo, repararlos a mi costa.</p>
<p>2. Respetar las medidas autorizadas{{ $t->dato('largo_m') ? ': largo '.$t->dato('largo_m').' m' : '' }}{{ $t->dato('ancho_m') ? ', ancho '.$t->dato('ancho_m').' m' : '' }}{{ $t->dato('altura_m') ? ', altura '.$t->dato('altura_m').' m' : '' }}{{ $t->dato('altura_total_m') ? ', altura total '.$t->dato('altura_total_m').' m' : '' }}.</p>
<p>3. Usar herramienta propia y trabajar sólo en el horario del panteón.</p>
<p>4. Retirar escombro y residuos de pasillos y lotes al terminar cada jornada y al concluir la obra.</p>
<p>5. Concluir los trabajos en un plazo de {{ $t->dato('tiempo_estimado_dias') }} días.</p>

<table class="datos" style="margin-top:8pt">
    <tr><td class="k">Descripción de la obra</td><td>{{ $t->dato('descripcion') }}</td></tr>
    <tr><td class="k">Materiales</td><td>{{ $t->dato('materiales') }}</td></tr>
    <tr><td class="k">Identificación del responsable</td><td class="linea"></td></tr>
</table>

@include('pdf.partes.firmas', ['firmas' => $firmas])
@endsection
