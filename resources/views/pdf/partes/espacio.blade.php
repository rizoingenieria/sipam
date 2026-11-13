{{-- Ubicación del espacio con los datos del art. 25 (panteón, sección, lote, fosa). --}}
<table class="datos">
    <tr><td class="k">Panteón</td><td>{{ $espacio->lote->seccion->panteon->nombre }}</td></tr>
    <tr><td class="k">Sección</td><td>{{ $espacio->lote->seccion->clave }}@if ($espacio->lote->seccion->nombre) ({{ $espacio->lote->seccion->nombre }})@endif</td></tr>
    <tr><td class="k">Lote</td><td>{{ $espacio->lote->numero }}</td></tr>
    <tr><td class="k">{{ $espacio->tipo->getLabel() }}</td><td>{{ $espacio->numero }} · clave {{ $espacio->clave_completa }}</td></tr>
</table>
