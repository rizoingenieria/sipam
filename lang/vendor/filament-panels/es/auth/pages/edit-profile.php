<?php

// «Mi cuenta» (App\Filament\Auth\MiCuenta): trato de «tú» y lenguaje llano.
return [
    'label' => 'Mi cuenta',
    'form' => [
        'email' => ['label' => 'Tu correo de usuario'],
        'name' => ['label' => 'Tu nombre'],
        'current_password' => [
            'below_content' => 'Por seguridad, escribe tu contraseña actual para confirmar el cambio.',
        ],
        'actions' => [
            'save' => ['label' => 'Guardar cambios'],
        ],
    ],
    'multi_factor_authentication' => [
        'label' => 'Verificación en dos pasos',
    ],
    'notifications' => [
        'saved' => ['title' => 'Se guardaron los cambios'],
    ],
    'actions' => [
        'cancel' => ['label' => 'Volver'],
    ],
];
