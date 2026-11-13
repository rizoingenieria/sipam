{{-- $firmas: lista de ['nombre' => ?, 'cargo' => ...]. Sin nombre registrado, queda la línea para escribirlo. --}}
<table class="firmas">
    <tr>
        @foreach ($firmas as $f)
            <td style="width:{{ floor(100 / count($firmas)) }}%">
                <div class="trazo">
                    <strong>{{ $f['nombre'] ?? ' ' }}</strong><br>
                    {{ $f['cargo'] }}
                </div>
            </td>
        @endforeach
    </tr>
</table>
