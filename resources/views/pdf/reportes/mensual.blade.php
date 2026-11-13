@extends('pdf.layout')
@section('contenido')
<p><strong>Informe de {{ $nombreMes }}</strong> ({{ $periodo->enPalabras() }}). Se rinde al superior jerárquico conforme al art. 21, frs. IV, XXIII y XXIV.</p>

<h2>Movimientos en los panteones</h2>
<table class="tabla">
    <tr><th>Panteón</th><th class="der">Inhumaciones</th><th class="der">Exhumaciones</th><th class="der">Reinhumaciones</th><th class="der">Traslados</th></tr>
    @forelse ($movimientos as $m)
        <tr><td>{{ $m->panteon }}</td><td class="der">{{ $m->inhumacion }}</td><td class="der">{{ $m->exhumacion }}</td><td class="der">{{ $m->reinhumacion }}</td><td class="der">{{ $m->traslado }}</td></tr>
    @empty
        <tr><td colspan="5">No hubo movimientos en el mes.</td></tr>
    @endforelse
</table>

<h2>Trámites</h2>
<table class="tabla">
    <tr><th>Trámite</th><th class="der">Recibidos</th><th class="der">Concluidos</th><th class="der">Rechazados</th><th class="der">Caducados</th><th class="der">En curso al cierre</th></tr>
    @foreach ($tramites as $t)
        <tr><td>{{ $t['tipo'] }}</td><td class="der">{{ $t['capturados'] }}</td><td class="der">{{ $t['concluidos'] }}</td><td class="der">{{ $t['rechazados'] }}</td><td class="der">{{ $t['caducados'] }}</td><td class="der">{{ $t['en_curso'] }}</td></tr>
    @endforeach
</table>

<h2>Ingresos (recibos de Tesorería del mes)</h2>
<table class="tabla">
    <tr><th>Concepto</th><th class="der">Órdenes</th><th class="der">Importe</th></tr>
    @forelse ($ingresos as $r)
        <tr><td>{{ $r->concepto }}</td><td class="der">{{ $r->ordenes }}</td><td class="der">{{ \App\Support\Formato::dinero($r->importe) }}</td></tr>
    @empty
        <tr><td colspan="3">No hubo pagos en el mes.</td></tr>
    @endforelse
    <tr class="total"><td>Total</td><td class="der">{{ $ingresos->sum('ordenes') }}</td><td class="der">{{ \App\Support\Formato::dinero($ingresos->sum('importe')) }}</td></tr>
</table>
<p style="margin-top:4pt">Órdenes emitidas pendientes de pago: {{ $pendientesDePago }}.</p>

<h2>Espacios disponibles (al {{ $generado }})</h2>
@include('pdf.reportes.disponibilidad', ['disponibilidad' => $disponibilidad])

@include('pdf.partes.firmas', ['firmas' => $firmas])
@endsection
