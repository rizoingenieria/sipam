{{-- Tabla de espacios por sección: compartida por el informe mensual. --}}
<table class="tabla">
    <tr><th>Panteón</th><th>Sección</th><th class="der">Espacios</th><th class="der">Ocupados</th><th class="der">Con título, vacíos</th><th class="der">Libres</th><th class="der">No disponibles</th></tr>
    @forelse ($disponibilidad as $d)
        <tr><td>{{ $d->panteon }}</td><td>{{ $d->seccion }}</td><td class="der">{{ $d->total }}</td><td class="der">{{ $d->ocupados }}</td><td class="der">{{ $d->con_titulo_vacios }}</td><td class="der">{{ $d->libres }}</td><td class="der">{{ $d->no_disponibles }}</td></tr>
    @empty
        <tr><td colspan="7">Aún no hay espacios registrados en el inventario.</td></tr>
    @endforelse
    @if ($disponibilidad->count() > 1)
        <tr class="total"><td colspan="2">Total</td><td class="der">{{ $disponibilidad->sum('total') }}</td><td class="der">{{ $disponibilidad->sum('ocupados') }}</td><td class="der">{{ $disponibilidad->sum('con_titulo_vacios') }}</td><td class="der">{{ $disponibilidad->sum('libres') }}</td><td class="der">{{ $disponibilidad->sum('no_disponibles') }}</td></tr>
    @endif
</table>
