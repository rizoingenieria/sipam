@extends('pdf.layout')
@section('contenido')
<table class="datos">
    <tr><td class="k">Trámite</td><td>{{ $t->folio }} · {{ $t->tipo->getLabel() }}</td></tr>
    <tr><td class="k">Contribuyente</td><td>{{ $t->solicitante->nombre_completo }}@if ($t->solicitante->curp) · CURP {{ $t->solicitante->curp }}@endif</td></tr>
    <tr><td class="k">Espacio</td><td>{{ $t->espacio->lote->seccion->panteon->nombre }}, sección {{ $t->espacio->lote->seccion->clave }}, lote {{ $t->espacio->lote->numero }}, {{ mb_strtolower($t->espacio->tipo->getLabel()) }} {{ $t->espacio->numero }}</td></tr>
</table>

<h2>Concepto a pagar</h2>
<table class="tabla">
    <tr><th>Concepto</th><th class="der" style="width:110pt">Importe</th></tr>
    <tr><td>{{ $orden->concepto->nombre }}</td><td class="der">{{ \App\Support\Formato::dinero($orden->importe) }}</td></tr>
    <tr><td class="der"><strong>Total</strong></td><td class="der"><strong>{{ \App\Support\Formato::dinero($orden->importe) }}</strong></td></tr>
</table>
<p style="margin-top:6pt"><strong>Importe con letra:</strong> {{ $conLetra }}</p>

<h2>Instrucciones</h2>
<p>1. Pague este importe únicamente en la <strong>Tesorería Municipal</strong>, presentando esta orden.</p>
<p>2. Conserve el recibo oficial que le entreguen.</p>
<p>3. Regrese con el recibo a la oficina de Panteones para continuar su trámite {{ $t->folio }}.</p>

@include('pdf.partes.firmas', ['firmas' => $firmas])
@endsection
