{{--
    Logo y fondo de la pantalla de acceso (gancho SIMPLE_LAYOUT_START, sólo en App\Filament\Auth\Acceso).
    La imagen de fondo se pasa como variable CSS para que la ruta salga de asset() y funcione en cualquier dominio.
--}}
<style>
    :root {
        --sp-acceso-fondo: image-set(url('{{ asset('images/acceso-fondo.webp') }}') type('image/webp'), url('{{ asset('images/acceso-fondo.jpg') }}') type('image/jpeg'));
    }
</style>
<div class="sp-acceso-marca">
    <img src="{{ asset('images/sipam-logo.png') }}" alt="SIPAM, Sistema Integral de Panteones Municipales" width="1049" height="284">
</div>
