{{-- Antes y después de cada campo. Estilos en línea con los tokens «Papel y tinta». --}}
@php $valor = fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'Sí' : 'No') : ($v === null || $v === '' ? '—' : (string) $v)); @endphp
<table style="width:100%;border-collapse:collapse;font-size:.95rem">
    <thead>
        <tr style="text-align:left;border-bottom:1px solid var(--sp-rule-strong)">
            <th style="padding:.4rem .5rem">Campo</th>
            <th style="padding:.4rem .5rem">Antes</th>
            <th style="padding:.4rem .5rem">Después</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($cambios as $campo => $c)
            <tr style="border-bottom:1px solid var(--sp-rule);vertical-align:top">
                <td style="padding:.4rem .5rem;font-weight:600">{{ str_replace('_', ' ', $campo) }}</td>
                <td style="padding:.4rem .5rem;word-break:break-word">{{ array_key_exists('antes', $c ?? []) ? $valor($c['antes']) : '—' }}</td>
                <td style="padding:.4rem .5rem;word-break:break-word">{{ array_key_exists('despues', $c ?? []) ? $valor($c['despues']) : '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
