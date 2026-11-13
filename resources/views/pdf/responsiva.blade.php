@extends('pdf.layout')
@section('contenido')
<p><strong>C. Secretario del Ayuntamiento</strong><br>Presente.</p>
<p>El que suscribe, Encargado de Panteones, en atención a la solicitud de adquisición del derecho de uso
presentada por <strong>{{ $t->solicitante->nombre_completo }}</strong>, quien manifiesta poseer el espacio
{{ $t->dato('tiempo_posesion') ? '(tiempo de posesión: '.$t->dato('tiempo_posesion').')' : '' }} (expediente {{ $t->folio }}),
y una vez revisados los registros del panteón y efectuado el cotejo del espacio que se describe:</p>

@include('pdf.partes.espacio', ['espacio' => $t->espacio])

<h2>Hace constar que</h2>
<p><span class="casilla"></span> <strong>No existe</strong> otro ciudadano con mayor derecho sobre este espacio.</p>
<p><span class="casilla"></span> <strong>Existe</strong> un ciudadano con mayor derecho sobre este espacio:</p>
<table style="margin:0 0 8pt 16pt;width:92%">
    <tr><td style="width:24%">Nombre:</td><td class="linea"></td></tr>
    <tr><td>Documento probatorio:</td><td class="linea"></td></tr>
</table>
<p>Se extiende la presente para los efectos del trámite de adquisición y de la notificación que se colocará
en los estrados del Ayuntamiento y en el lote.</p>

@include('pdf.partes.firmas', ['firmas' => $firmas])
@endsection
