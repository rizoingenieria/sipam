<?php

return [
    'label' => 'Activar',

    'modal' => [
        'heading' => 'Activar el código del celular',

        'description' => <<<'BLADE'
            Instala en tu celular una aplicación gratuita de códigos, por ejemplo Google Authenticator (<x-filament::link href="https://apps.apple.com/app/google-authenticator/id388497605" target="_blank">iPhone</x-filament::link>, <x-filament::link href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank">Android</x-filament::link>) o Microsoft Authenticator. Después sigue estos pasos.
            BLADE,

        'content' => [
            'qr_code' => [
                'instruction' => '1. Abre la aplicación, elige agregar una cuenta y apunta la cámara a este código:',
                'alt' => 'Código QR para agregar SIPAM a la aplicación de códigos',
            ],
            'text_code' => [
                'instruction' => 'Si la cámara no funciona, escribe esta clave en la aplicación:',
                'messages' => ['copied' => 'Copiada'],
            ],
            'recovery_codes' => [
                'instruction' => '4. Guarda estos códigos de recuperación: imprímelos o anótalos y guárdalos en un lugar seguro, lejos del celular. Sirven para entrar si pierdes el celular. Cada uno se usa una sola vez y no se volverán a mostrar.',
            ],
        ],

        'form' => [
            'password' => [
                'label' => '3. Escribe tu contraseña de SIPAM',
                'validation_attribute' => 'contraseña actual',
            ],
            'code' => [
                'label' => '2. Escribe el código de 6 números que ahora muestra la aplicación',
                'validation_attribute' => 'código',
                'below_content' => 'Desde ahora, cada vez que entres a SIPAM te pediremos este código.',
                'messages' => [
                    'invalid' => 'El código no es correcto. Escribe el que aparece ahora en la aplicación; cambia cada 30 segundos.',
                    'rate_limited' => 'Demasiados intentos. Espera un momento y vuelve a intentarlo.',
                ],
            ],
        ],

        'actions' => [
            'submit' => ['label' => 'Ya los guardé: activar'],
        ],
    ],

    'notifications' => [
        'enabled' => ['title' => 'Listo: el código del celular quedó activado'],
    ],
];
