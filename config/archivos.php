<?php

return [
    /*
     * Tope de subida del servidor, en MB, para cualquier archivo (trámites y acervo).
     * Debe coincidir con upload_max_filesize/post_max_size de PHP y client_max_body_size de Nginx
     * (en la VM y en el proxy). Los parámetros de la pantalla pueden pedir menos, nunca más.
     */
    'subida_maxima_mb' => (int) env('SUBIDA_MAXIMA_MB', 100),
];
