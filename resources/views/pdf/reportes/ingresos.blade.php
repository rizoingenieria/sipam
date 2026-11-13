@extends('pdf.layout')
@section('contenido')
<p><strong>Periodo:</strong> {{ $periodo->enPalabras() }} (por fecha del recibo de Tesorería)</p>
<h2>Resumen por concepto</h2>
<table class="tabla">
    <tr><th>Concepto</th><th class="der">Órdenes</th><th class="der">Importe</th></tr>
    @foreach ($resumen as $r)
        <tr><td>{{ $r->concepto }}</td><td class="der">{{ $r->ordenes }}</td><td class="der">{{ \App\Support\Formato::dinero($r->importe) }}</td></tr>
    @endforeach
    <tr class="total"><td>Total</td><td class="der">{{ $resumen->sum('ordenes') }}</td><td class="der">{{ \App\Support\Formato::dinero($resumen->sum('importe')) }}</td></tr>
</table>
<p style="margin-top:4pt">{{ \App\Support\EnLetra::pesos($resumen->sum('importe')) }}</p>

<h2>Detalle para conciliar con Tesorería</h2>
<table class="reporte">
    <thead><tr><th>Fecha del recibo</th><th>Recibo</th><th>Orden</th><th>Trámite</th><th>Contribuyente</th><th>Concepto</th><th class="cifra">Importe</th></tr></thead>
    <tbody>
        @forelse ($ordenes as $o)
            <tr>
                <td>{{ $o->pagada_en->format('d/m/Y') }}</td><td>{{ $o->recibo_folio }}</td><td>{{ $o->folio }}</td><td>{{ $o->tramite->folio }}</td>
                <td>{{ $o->tramite->solicitante->nombre_completo }}</td><td>{{ $o->concepto->nombre }}</td><td class="cifra">{{ \App\Support\Formato::dinero($o->importe) }}</td>
            </tr>
        @empty
            <tr><td colspan="7">No hay pagos registrados en el periodo.</td></tr>
        @endforelse
    </tbody>
</table>
<p style="margin-top:6pt">Órdenes emitidas pendientes de pago al cierre del periodo: {{ $pendientes }}.</p>
@include('pdf.partes.firmas', ['firmas' => $firmas])
@endsection
