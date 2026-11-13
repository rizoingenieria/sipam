{{--
    Hoja carta para documentos oficiales. dompdf: sólo tablas y bloques (sin flex ni grid),
    fuente DejaVu Sans (trae acentos). Tinta única azul-negra, renglones finos; sin color de relleno
    para que salga bien en impresora de oficina y en fotocopia.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $nombreDocumento }} {{ $folio }}</title>
<style>
    @page { margin: 1.6cm 1.8cm 2.2cm 1.8cm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 9.6pt; color: #16242f; line-height: 1.45; }
    h1 { font-size: 15pt; margin: 0; letter-spacing: .5pt; }
    h2 { font-size: 10.5pt; margin: 14pt 0 5pt; padding-bottom: 2pt; border-bottom: .6pt solid #16242f; page-break-after: avoid; }
    p { margin: 0 0 7pt; text-align: justify; }
    table { border-collapse: collapse; width: 100%; }
    .membrete td { vertical-align: middle; }
    .ente { font-size: 11pt; font-weight: bold; }
    .sub { font-size: 8.4pt; color: #4b5b66; }
    .folio { text-align: right; font-size: 9pt; }
    .folio strong { font-size: 12pt; display: block; }
    .raya { border-top: 1.4pt solid #16242f; margin: 8pt 0 12pt; }
    .datos td { padding: 3.5pt 6pt; border-bottom: .5pt solid #9fb8a5; vertical-align: top; }
    .datos td.k { width: 30%; font-weight: bold; }
    .tabla th { text-align: left; font-size: 8.6pt; padding: 4pt 6pt; border-bottom: 1pt solid #16242f; }
    .tabla td { padding: 5pt 6pt; border-bottom: .5pt solid #9fb8a5; }
    .der, .tabla th.der, .reporte th.cifra { text-align: right; }
    .fundamento { border: .7pt solid #16242f; padding: 6pt 8pt; font-size: 8.2pt; margin-top: 12pt; }
    .casilla { display: inline-block; width: 9pt; height: 9pt; border: .8pt solid #16242f; margin-right: 6pt; }
    .linea { border-bottom: .6pt solid #16242f; height: 14pt; }
    .firmas { margin-top: 30pt; }
    .firmas td { text-align: center; vertical-align: top; padding: 0 10pt; font-size: 8.8pt; }
    .firmas .trazo { border-top: .8pt solid #16242f; padding-top: 4pt; }
    .sello { position: fixed; top: 34%; left: 0; right: 0; text-align: center; font-size: 64pt; font-weight: bold;
             color: #c3d4c6; transform: rotate(-24deg); letter-spacing: 6pt; }
    .pie { position: fixed; bottom: -1.3cm; left: 0; right: 0; font-size: 7.4pt; color: #4b5b66; border-top: .5pt solid #9fb8a5; padding-top: 3pt; }
    .grande { font-size: 12.5pt; line-height: 1.55; }
    /* Reportes: tablas largas que siguen en varias hojas, con encabezado repetido. */
    .reporte { font-size: 7.4pt; }
    .reporte th { font-size: 7pt; padding: 3pt 3pt; border-bottom: 1pt solid #16242f; text-align: left; vertical-align: bottom; }
    .reporte td { padding: 2.5pt 3pt; border-bottom: .4pt solid #9fb8a5; vertical-align: top; }
    .reporte thead { display: table-header-group; }
    .reporte tr { page-break-inside: avoid; }
    .cifra { text-align: right; white-space: nowrap; }
    .total td { font-weight: bold; border-top: 1pt solid #16242f; }
</style>
</head>
<body>
@if (! empty($sello))
    <div class="sello">{{ $sello }}</div>
@endif
<div class="pie">
    {{ $ente?->nombre_ente }}@if ($ente?->domicilio) · {{ $ente->domicilio }}@endif @if ($ente?->telefono) · Tel. {{ $ente->telefono }}@endif
    @if ($ente?->leyenda_documentos)<br>{{ $ente->leyenda_documentos }}@endif
</div>

<table class="membrete">
    <tr>
        @if ($escudo)
            <td style="width:62pt"><img src="{{ $escudo }}" style="height:56pt"></td>
        @endif
        <td>
            <div class="ente">{{ $ente?->nombre_ente ?? 'Ayuntamiento' }}</div>
            <div class="sub">{{ $ente?->municipio }}, {{ $ente?->estado }}</div>
            <div class="sub">{{ $area ?? 'Panteones Municipales' }}</div>
        </td>
        <td class="folio" style="width:215pt">
            <h1 style="text-align:right">{{ $nombreDocumento }}</h1>
            @if ($folio)<strong>{{ $folio }}</strong>@endif
            <span class="sub">{{ $lugarFecha }}</span>
        </td>
    </tr>
</table>
<div class="raya"></div>

@yield('contenido')

@if (! empty($fundamento))
    <div class="fundamento"><strong>Fundamento:</strong> {{ $fundamento }}@if ($ente?->reglamento_cita) — {{ $ente->reglamento_cita }}.@endif</div>
@endif
</body>
</html>
