@extends('pdf.layout')
@section('contenido')
<p class="grande">Yo, <strong>{{ $p->persona->nombre_completo }}</strong>, en mi carácter de {{ $caracter }},
otorgo mi consentimiento para
@if ($t->tipo === \App\Enums\TipoTramite::Inhumacion)
    la <strong>inhumación</strong> de <strong>{{ $finado }}</strong>
@else
    la <strong>exhumación</strong> de los restos de <strong>{{ $finado }}</strong>
    por motivo de {{ mb_strtolower($t->motivo_exhumacion?->getLabel() ?? '') }}
@endif
en el siguiente espacio, y para la apertura de la fosa que esto requiere:</p>

@include('pdf.partes.espacio', ['espacio' => $t->espacio])

@if ($t->tipo === \App\Enums\TipoTramite::Exhumacion)
    <table class="datos">
        <tr><td class="k">Destino de los restos</td><td>
            @switch($t->dato('destino.tipo'))
                @case('traslado') Traslado a {{ $t->dato('destino.destino_externo') }}, a cargo de {{ $t->dato('destino.realizado_por') }} @break
                @case('otro_espacio') Reinhumación en {{ \App\Models\Espacio::find($t->dato('destino.espacio_destino_id'))?->clave_completa }} @break
                @default Al pie de la misma fosa
            @endswitch
        </td></tr>
    </table>
@endif

<table class="datos" style="margin-top:8pt">
    <tr><td class="k">Expediente</td><td>{{ $t->folio }}</td></tr>
    <tr><td class="k">Identificación presentada</td><td class="linea"></td></tr>
</table>
<p style="margin-top:8pt">Declaro, bajo protesta de decir verdad, que los datos asentados son ciertos y que me presento personalmente a firmar.</p>

@include('pdf.partes.firmas', ['firmas' => $firmas])
@endsection
