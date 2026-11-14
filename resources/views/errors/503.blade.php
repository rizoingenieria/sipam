{{-- Página de mantenimiento (php artisan down): en español y sin depender de Filament ni de la base de datos. --}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="30">
    <title>SIPAM — En mantenimiento</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #faf3ec; color: #2b1f22;
               font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 18px; line-height: 1.5; }
        main { max-width: 32rem; margin: 1.5rem; padding: 2.5rem 2rem; background: #fff; border-radius: 20px; text-align: center;
               box-shadow: 0 30px 60px -30px rgb(43 31 34 / .35); }
        img { width: min(260px, 70vw); height: auto; }
        h1 { margin: 1.5rem 0 .5rem; font-size: 1.5rem; color: #7a1029; }
        p { margin: 0; color: #5e5256; }
    </style>
</head>
<body>
<main>
    <img src="{{ asset('images/sipam-logo.png') }}" alt="SIPAM, Sistema Integral de Panteones Municipales">
    <h1>Estamos actualizando el sistema</h1>
    <p>Vuelve a estar disponible en unos minutos. Esta página se recarga sola; no necesitas hacer nada.</p>
</main>
</body>
</html>
