{{--
    Lista de arranque. Sólo componentes nativos y estilos en línea: Filament no compila clases
    arbitrarias en vistas propias. Colores de los tokens «Papel y tinta».
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            @if ($pendientes->isEmpty())
                Todo está listo para operar
            @elseif ($bloqueantes > 0)
                Antes de operar, falta resolver {{ $bloqueantes === 1 ? '1 cosa' : $bloqueantes.' cosas' }}
            @else
                El sistema puede operar; revisa estos pendientes
            @endif
        </x-slot>
        <x-slot name="description">
            @if ($pendientes->isEmpty())
                No falta nada por configurar.
            @else
                Cada punto dice qué pasa y qué hacer. Se actualiza solo cuando lo resuelves.
            @endif
        </x-slot>

        @if ($pendientes->isNotEmpty())
            <ul style="margin:0;padding:0;list-style:none">
                @foreach ($pendientes as $p)
                    <li style="display:flex;gap:1rem;align-items:flex-start;padding:1rem 0;{{ $loop->first ? '' : 'border-top:1px solid var(--sp-rule);' }}">
                        <x-filament::badge :color="$p['bloquea'] ? 'danger' : 'warning'" style="flex-shrink:0;margin-top:.15rem">
                            {{ $p['bloquea'] ? 'Bloquea' : 'Revisar' }}
                        </x-filament::badge>
                        <div style="min-width:0;flex:1">
                            <p style="margin:0;font-size:.875rem;color:var(--sp-ink-soft)">{{ $p['area'] }}</p>
                            <p style="margin:.125rem 0 0;font-weight:600;color:var(--sp-ink);text-wrap:pretty">{{ $p['problema'] }}</p>
                            <p style="margin:.25rem 0 0;color:var(--sp-ink-soft);text-wrap:pretty">{{ $p['queHacer'] }}</p>
                        </div>
                        @if ($p['enlace'])
                            <x-filament::link :href="$p['enlace'][1]" style="flex-shrink:0;white-space:nowrap;margin-top:.15rem">
                                {{ $p['enlace'][0] }}
                            </x-filament::link>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
