@extends('pdf.layout')
@section('contenido')
<p class="grande" style="text-align:center;font-size:20pt;font-weight:bold;margin:10pt 0 14pt">AVISO AL PÚBLICO</p>
<p class="grande">Se hace del conocimiento público que <strong>{{ $t->solicitante->nombre_completo }}</strong>
solicitó la <strong>adquisición del derecho de uso</strong> del espacio que se describe, el cual manifiesta poseer
{{ $t->dato('tiempo_posesion') ? '(tiempo de posesión: '.$t->dato('tiempo_posesion').')' : '' }}:</p>

<div class="grande">@include('pdf.partes.espacio', ['espacio' => $t->espacio])</div>

<p class="grande" style="margin-top:12pt">Cualquier persona que se considere con <strong>mejor derecho</strong> sobre este espacio
puede presentarse con los documentos que lo acrediten ante la <strong>Secretaría del Ayuntamiento</strong>@if ($ente?->domicilio), ubicada en {{ $ente->domicilio }}@endif,
citando el expediente <strong>{{ $t->folio }}</strong>.</p>

@include('pdf.partes.firmas', ['firmas' => $firmas])
@endsection
