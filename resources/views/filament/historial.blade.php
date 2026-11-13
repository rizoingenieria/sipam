{{-- Historial de versiones. Estilos en línea con los tokens «Papel y tinta». --}}
<div style="overflow-x:auto;border:1px solid var(--sp-rule);border-radius:14px;background:var(--sp-sheet)">
    <table style="width:100%;border-collapse:collapse;text-align:left">
        <thead>
            <tr>
                <th style="padding:.75rem 1rem .75rem 1.5rem;border-bottom:1px solid var(--sp-rule);font-size:.875rem;font-weight:600;color:var(--sp-ink-soft)">Desde</th>
                <th style="padding:.75rem 1rem;border-bottom:1px solid var(--sp-rule);font-size:.875rem;font-weight:600;color:var(--sp-ink-soft)">Hasta</th>
                <th style="padding:.75rem 1.5rem .75rem 1rem;border-bottom:1px solid var(--sp-rule);font-size:.875rem;font-weight:600;color:var(--sp-ink-soft)">Valor</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($versiones as $v)
                <tr>
                    <td style="padding:.75rem 1rem .75rem 1.5rem;{{ $loop->last ? '' : 'border-bottom:1px solid var(--sp-rule);' }}white-space:nowrap;font-variant-numeric:tabular-nums">{{ $v['desde'] }}</td>
                    <td style="padding:.75rem 1rem;{{ $loop->last ? '' : 'border-bottom:1px solid var(--sp-rule);' }}white-space:nowrap;font-variant-numeric:tabular-nums">
                        @if ($v['actual'])
                            <x-filament::badge color="success">Vigente</x-filament::badge>
                        @else
                            {{ $v['hasta'] }}
                        @endif
                    </td>
                    <td style="padding:.75rem 1.5rem .75rem 1rem;{{ $loop->last ? '' : 'border-bottom:1px solid var(--sp-rule);' }}">{{ $v['valor'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
