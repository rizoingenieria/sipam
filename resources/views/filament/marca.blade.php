{{--
    Marca de la barra superior: el logo de SIPAM y, separado por una línea, el ayuntamiento que lo usa.
    El nombre del municipio sale de «Datos del ayuntamiento»: el mismo sistema sirve a otro municipio sin tocar código.
    Estilos en línea con las variables de sipam.css: Filament no compila clases arbitrarias en vistas propias.
--}}
@php
    $municipio = $ente?->municipio;
    $sistema = $ente?->nombre_sistema ?: config('app.name');
@endphp
<span style="display:flex;align-items:center;gap:.875rem;line-height:1.2">
    {{-- Versión corta (arco y SIPAM): el subtítulo del logo completo no se lee a este tamaño. --}}
    <img src="{{ asset('images/sipam-logo-corto.png') }}" alt="{{ $sistema }}" style="height:2.1rem;width:auto">
    @if ($municipio)
        <span aria-hidden="true" style="align-self:stretch;width:1px;background:var(--sp-rule-strong)"></span>
        <span style="display:flex;flex-direction:column;font-size:.875rem">
            <span style="color:var(--sp-ink-soft)">Ayuntamiento de</span>
            <span style="font-weight:700;color:var(--sp-ink)">{{ $municipio }}</span>
        </span>
    @endif
</span>
