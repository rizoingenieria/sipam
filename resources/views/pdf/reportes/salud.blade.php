@extends('pdf.layout')
@section('contenido')
<p><strong>A la Secretaría de Salud</strong> (Jurisdicción Sanitaria correspondiente)<br>Presente.</p>
<p>En cumplimiento del art. 21, fr. XXX, se remite la información de los panteones públicos existentes en el territorio
municipal correspondiente al año {{ $anio }}.</p>

<table class="tabla" style="font-size:8.6pt">
    <tr><th>Denominación</th><th>Ubicación</th><th class="der">Extensión (m²)</th><th class="der">Capacidad</th><th class="der">Espacios registrados</th><th class="der">Ocupados</th><th class="der">Libres</th><th class="der">Inhumaciones {{ $anio }}</th><th class="der">Exhumaciones {{ $anio }}</th><th>Situación</th></tr>
    @forelse ($panteones as $p)
        <tr>
            <td>{{ $p->nombre }}</td>
            <td>{{ $p->domicilio ?? 'Sin dato' }}</td>
            <td class="der">{{ $p->extension !== null ? \App\Support\Formato::numero($p->extension, 2) : 'Sin dato' }}</td>
            <td class="der">{{ $p->capacidad !== null ? \App\Support\Formato::numero($p->capacidad) : 'Sin dato' }}</td>
            <td class="der">{{ \App\Support\Formato::numero($p->registrados) }}</td>
            <td class="der">{{ \App\Support\Formato::numero($p->ocupados) }}</td>
            <td class="der">{{ \App\Support\Formato::numero($p->libres) }}</td>
            <td class="der">{{ $p->inhumaciones }}</td>
            <td class="der">{{ $p->exhumaciones }}</td>
            <td>{{ $p->estado->getLabel() }}</td>
        </tr>
    @empty
        <tr><td colspan="10">No hay panteones registrados.</td></tr>
    @endforelse
</table>
<p style="margin-top:6pt;font-size:8pt">«Capacidad» es la declarada para el panteón; «espacios registrados», los que hay en el inventario del sistema.
Ocupados y libres se calculan al día en que se genera este informe.</p>

@include('pdf.partes.firmas', ['firmas' => $firmas])
@endsection
