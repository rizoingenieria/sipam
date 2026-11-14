<?php

return [
    'label' => 'Desactivar',

    'modal' => [
        'heading' => 'Desactivar el código del celular',
        'description' => 'Si lo desactivas, cualquiera que sepa tu contraseña podrá entrar con tu cuenta. Si tu rol lo exige, te lo pediremos de nuevo al entrar.',

        'form' => [
            'password' => [
                'label' => 'Tu contraseña actual',
                'validation_attribute' => 'contraseña actual',
            ],
            'code' => [
                'label' => 'Escribe el código de 6 números que muestra la aplicación de tu celular',
                'validation_attribute' => 'código',
                'actions' => [
                    'use_recovery_code' => ['label' => 'No tengo mi celular: usar un código de recuperación'],
                ],
                'messages' => [
                    'invalid' => 'El código no es correcto. Escribe el que aparece ahora en la aplicación.',
                    'rate_limited' => 'Demasiados intentos. Espera un momento y vuelve a intentarlo.',
                ],
            ],
            'recovery_code' => [
                'label' => 'Escribe uno de tus códigos de recuperación',
                'validation_attribute' => 'código de recuperación',
                'messages' => [
                    'invalid' => 'Ese código de recuperación no es correcto o ya se usó.',
                    'rate_limited' => 'Demasiados intentos. Espera un momento y vuelve a intentarlo.',
                ],
            ],
        ],

        'actions' => [
            'submit' => ['label' => 'Desactivar'],
        ],
    ],

    'notifications' => [
        'disabled' => ['title' => 'El código del celular quedó desactivado'],
    ],
];
