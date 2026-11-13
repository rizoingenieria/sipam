<?php

return [
    // Carpeta donde se guardan los respaldos (fuera de la carpeta pública).
    'ruta' => env('RESPALDOS_RUTA', storage_path('app/respaldos')),

    // Días que se conservan los respaldos en el servidor. Copia los recientes a otro equipo o a la nube.
    'dias' => (int) env('RESPALDOS_DIAS', 30),

    // Programa para respaldar MySQL; en algunos servidores está en otra ruta (p. ej. /usr/bin/mysqldump).
    'mysqldump' => env('RESPALDOS_MYSQLDUMP', 'mysqldump'),
];
