{{-- Cuotas que faltan. Estilos en línea con los tokens «Papel y tinta». --}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            @if ($faltan === [])
                Ya están todas las cuotas
            @else
                {{ count($faltan) === 1 ? 'Falta 1 cuota' : 'Faltan '.count($faltan).' cuotas' }}
            @endif
        </x-slot>
        <x-slot name="description">
            @if ($faltan === []) Ya se pueden emitir todas las órdenes de pago. @else Sin ellas no se pueden emitir esas órdenes de pago. Captúralas con «Capturar cuota» y el acuerdo de Cabildo que las aprueba. @endif
        </x-slot>
        <ul style="margin:0;padding:0;list-style:none;display:grid;grid-template-columns:repeat(auto-fill,minmax(18rem,1fr));gap:.25rem 1.5rem">
            @foreach ($faltan as $f)
                <li style="padding:.35rem 0;border-bottom:1px solid var(--sp-rule)">{{ $f }}</li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
