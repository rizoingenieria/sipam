<?php

// Verificación en dos pasos con aplicación de códigos: trato de «tú» y lenguaje llano.
return [
    'management_schema' => [
        'actions' => [
            'label' => 'Código del celular',
            'below_content' => 'Al entrar, además de tu contraseña, escribes un código de 6 números que te da una aplicación en tu celular. Así, aunque alguien sepa tu contraseña, no puede entrar sin tu celular.',
            'messages' => [
                'enabled' => 'Activada',
                'disabled' => 'Sin activar',
            ],
        ],
    ],

    'login_form' => [
        'label' => 'Código de la aplicación del celular',
        'code' => [
            'label' => 'Escribe el código de 6 números que muestra la aplicación de tu celular',
            'validation_attribute' => 'código',
            'actions' => [
                'use_recovery_code' => ['label' => 'No tengo mi celular: usar un código de recuperación'],
            ],
            'messages' => [
                'invalid' => 'El código no es correcto. Escribe el que aparece ahora en la aplicación; cambia cada 30 segundos.',
            ],
        ],
        'recovery_code' => [
            'label' => 'Escribe uno de tus códigos de recuperación',
            'validation_attribute' => 'código de recuperación',
            'messages' => [
                'invalid' => 'Ese código de recuperación no es correcto o ya se usó.',
            ],
        ],
    ],
];
