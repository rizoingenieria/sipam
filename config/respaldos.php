<?php

return [
    // Carpeta donde se guardan los respaldos (fuera de la carpeta pública).
    'ruta' => env('RESPALDOS_RUTA', storage_path('app/respaldos')),

    // Días que se conservan los respaldos en el servidor. Copia los recientes a otro equipo o a la nube.
    'dias' => (int) env('RESPALDOS_DIAS', 30),

    // Copia del acervo histórico: se agrega sólo lo nuevo cada noche y nunca se borra nada de ella.
    // Conviene que esté en otro disco o que se sincronice a otro equipo.
    'acervo_ruta' => env('RESPALDOS_ACERVO_RUTA', rtrim(env('RESPALDOS_RUTA', storage_path('app/respaldos')), '/').'/acervo'),

    // Programa para respaldar MySQL; en algunos servidores está en otra ruta (p. ej. /usr/bin/mysqldump).
    'mysqldump' => env('RESPALDOS_MYSQLDUMP', 'mysqldump'),
];
