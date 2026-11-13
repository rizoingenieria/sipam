@extends('pdf.layout')
@section('contenido')
<p style="margin-bottom:6pt"><strong>Periodo:</strong> {{ $periodo->enPalabras() }}@if ($tipo) · <strong>Movimiento:</strong> {{ $tipo->getLabel() }}@endif @if ($panteon) · <strong>Panteón:</strong> {{ $panteon }}@endif · <strong>Registros:</strong> {{ count($renglones) }}</p>
<table class="reporte">
    <thead>
        <tr>@foreach ($columnas as $titulo)<th>{{ $titulo }}</th>@endforeach</tr>
    </thead>
    <tbody>
        @forelse ($renglones as $r)
            <tr>@foreach (array_keys($columnas) as $c)<td>{{ $r[$c] ?? '' }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($columnas) }}">No hay movimientos en el periodo.</td></tr>
        @endforelse
    </tbody>
</table>
@if (! $verCausa)
    <p style="margin-top:6pt;font-size:7.4pt">La causa de fallecimiento se muestra como «Reservada» porque quien generó el libro no tiene permiso de verla.</p>
@endif
@include('pdf.partes.firmas', ['firmas' => $firmas])
@endsection
