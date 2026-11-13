@extends('pdf.layout')
@section('contenido')
<p style="text-align:center;font-size:16pt;font-weight:bold;margin:6pt 0 2pt">TÍTULO OFICIAL DE DERECHO DE USO</p>
<p style="text-align:center;margin-bottom:8pt">{{ $titulo->regimen->nombre }}@if ($titulo->regimen->duracion_anios < 99) · vigente hasta el {{ \App\Support\EnLetra::fecha($titulo->fecha_vencimiento) }}@endif</p>

<p style="font-size:10.5pt">El H. Ayuntamiento, por conducto de la Secretaría del Ayuntamiento y en coordinación con el Edil del Ramo,
otorga el derecho de uso del siguiente espacio a favor de:</p>

<table class="tabla" style="margin-bottom:10pt">
    <tr><th>Nombre</th><th style="width:120pt">Carácter</th></tr>
    @forelse ($titulo->titulares as $p)
        <tr><td style="font-size:11pt"><strong>{{ $p->nombre_completo }}</strong></td><td>{{ $loop->first ? 'Titular' : 'Copropietario' }}</td></tr>
    @empty
        <tr><td colspan="2">Sin titular registrado</td></tr>
    @endforelse
</table>

<table class="datos">
    <tr><td class="k">I. Número de folio</td><td>{{ $titulo->folio }}</td></tr>
    <tr><td class="k">II. Número de expediente</td><td>{{ $titulo->expediente }}</td></tr>
    <tr><td class="k">III. Número de {{ mb_strtolower($titulo->espacio->tipo->getLabel()) }}</td><td>{{ $titulo->espacio->numero }}</td></tr>
    <tr><td class="k">IV. Sección</td><td>{{ $titulo->espacio->lote->seccion->clave }}</td></tr>
    <tr><td class="k">V. Lote</td><td>{{ $titulo->espacio->lote->numero }}</td></tr>
    <tr><td class="k">VI. Panteón</td><td>{{ $titulo->espacio->lote->seccion->panteon->nombre }}</td></tr>
    <tr><td class="k">VII. Beneficiarios</td><td>{{ $titulo->beneficiarios->pluck('nombre_completo')->implode(', ') ?: 'No designados' }}</td></tr>
    <tr><td class="k">VIII. Fecha de emisión</td><td>{{ $fechaLetra }}</td></tr>
</table>

<p style="margin-top:10pt;font-size:8.6pt">El derecho de uso se sujeta a lo dispuesto por el Reglamento de Panteones. Cualquier cambio de titular
se tramita ante la Secretaría del Ayuntamiento (arts. 27 y 67).</p>

@include('pdf.partes.firmas', ['firmas' => $firmas])
<p style="text-align:center;font-size:8pt;margin-top:4pt">IX. Firma autógrafa del Secretario del Ayuntamiento.</p>
@endsection
