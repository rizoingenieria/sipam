<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
 * Tareas programadas. En el servidor basta UNA línea en el cron del usuario del sistema:
 *   * * * * * cd /ruta/del/sistema && php artisan schedule:run >> /dev/null 2>&1
 * (ver «Guía de puesta en producción»).
 */

// Adquisiciones que pasaron su plazo sin concluir (art. 34): de madrugada, cuando nadie trabaja.
Schedule::command('panteones:caducar-tramites')->dailyAt('01:10')->withoutOverlapping();

// Respaldo de la base y de los archivos de los trámites, con limpieza de los antiguos.
Schedule::command('panteones:respaldar')->dailyAt('02:30')->withoutOverlapping();

// Acervo histórico: copia de los archivos nuevos (aparte, porque pesan mucho y no cambian) y revisión
// de que sigan intactos (cada noche los que no se han revisado en 30 días: todo el acervo, una vez al mes).
Schedule::command('panteones:respaldar-acervo')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('panteones:verificar-acervo')->dailyAt('04:00')->withoutOverlapping();

// Latido: permite a «panteones:verificar-instalacion» saber que el cron sí está corriendo.
Schedule::call(fn () => Cache::forever('panteones:latido', now()->toIso8601String()))->everyFiveMinutes()->name('latido');
