<?php

return [
    'label' => 'Nuevos códigos de recuperación',

    'modal' => [
        'heading' => 'Crear nuevos códigos de recuperación',
        'description' => 'Úsalo si perdiste tus códigos o ya usaste varios. Los códigos anteriores dejarán de servir en ese momento.',

        'form' => [
            'code' => [
                'label' => 'Escribe el código de 6 números que muestra la aplicación de tu celular',
                'validation_attribute' => 'código',
                'messages' => [
                    'invalid' => 'El código no es correcto. Escribe el que aparece ahora en la aplicación.',
                    'rate_limited' => 'Demasiados intentos. Espera un momento y vuelve a intentarlo.',
                ],
            ],
            'password' => [
                'label' => 'Tu contraseña actual',
                'validation_attribute' => 'contraseña',
            ],
        ],

        'actions' => [
            'submit' => ['label' => 'Crear nuevos códigos'],
        ],
    ],

    'notifications' => [
        'regenerated' => ['title' => 'Se crearon tus nuevos códigos de recuperación'],
    ],

    'show_new_recovery_codes' => [
        'modal' => [
            'heading' => 'Tus nuevos códigos de recuperación',
            'description' => 'Imprímelos o anótalos y guárdalos en un lugar seguro, lejos del celular. Cada uno se usa una sola vez y no se volverán a mostrar.',
            'actions' => [
                'submit' => ['label' => 'Ya los guardé'],
            ],
        ],
    ],
];
