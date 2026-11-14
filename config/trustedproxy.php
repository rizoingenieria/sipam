<?php

/*
 * Proxy reverso de confianza (Nginx en otra máquina que recibe el https y reenvía aquí).
 * Sólo a estas IP se les cree lo que dicen las cabeceras X-Forwarded-* (protocolo, host, IP del visitante).
 * Sin esto, detrás de un proxy Laravel cree que la conexión es http: genera enlaces http, la cookie
 * segura no viaja y la bitácora anota la IP del proxy en vez de la del visitante.
 *
 * TRUSTED_PROXIES=192.168.1.2          una o varias IP separadas por coma
 * Vacío: no se confía en ningún proxy (servidor expuesto directamente).
 * Nunca «*» en un servidor al que se pueda llegar sin pasar por el proxy.
 */
return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
