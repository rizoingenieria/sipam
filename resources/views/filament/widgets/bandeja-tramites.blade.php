{{-- Bandeja de trámites. Estilos en línea con los tokens «Papel y tinta». --}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Trámites que esperan</x-slot>
        <x-slot name="description">Toca una tarjeta para ver la lista.</x-slot>
        @if ($nuevo)
            <x-slot name="afterHeader">
                <x-filament::button tag="a" :href="$nuevo" icon="heroicon-o-plus" size="lg">Nuevo trámite</x-filament::button>
            </x-slot>
        @endif

        @if (empty($tarjetas))
            <p style="margin:0">Tu usuario sólo consulta. Busca un trámite en el menú «Trámites».</p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:1rem">
                @foreach ($tarjetas as $t)
                    <a href="{{ $t['url'] }}" style="display:block;padding:1rem 1.25rem;border:1px solid {{ ($t['alerta'] ?? false) ? 'var(--sp-caution-line)' : 'var(--sp-rule)' }};border-radius:12px;background:var(--sp-field);text-decoration:none;color:var(--sp-ink)">
                        <span style="display:block;font-size:2.4rem;font-weight:700;line-height:1">{{ $t['n'] }}</span>
                        <span style="display:block;font-weight:700;margin-top:.35rem">{{ $t['titulo'] }}</span>
                        <span style="display:block;color:var(--sp-ink-soft);font-size:.95rem">{{ $t['texto'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
