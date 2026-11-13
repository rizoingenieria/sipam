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

// Latido: permite a «panteones:verificar-instalacion» saber que el cron sí está corriendo.
Schedule::call(fn () => Cache::forever('panteones:latido', now()->toIso8601String()))->everyFiveMinutes()->name('latido');
