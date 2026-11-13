<?php

// Sólo lo que cambia respecto de Filament: trato de «tú» y lenguaje llano (guía de redacción).
return [
    'title' => 'Iniciar sesión',
    'heading' => 'Iniciar sesión',
    'actions' => [
        'request_password_reset' => ['label' => '¿Olvidaste tu contraseña?'],
    ],
    'form' => [
        'email' => ['label' => 'Tu correo de usuario'],
        'password' => ['label' => 'Tu contraseña'],
        'remember' => ['label' => 'Mantener la sesión abierta en esta computadora'],
        'actions' => ['authenticate' => ['label' => 'Iniciar sesión']],
    ],
    'multi_factor' => [
        'heading' => 'Confirma que eres tú',
        'subheading' => 'Para entrar, confirma tu identidad.',
    ],
    'messages' => [
        'failed' => 'El correo o la contraseña no coinciden. Revísalos e inténtalo de nuevo.',
    ],
    'notifications' => [
        'throttled' => [
            'title' => 'Demasiados intentos',
            'body' => 'Espera :seconds segundos y vuelve a intentarlo.',
        ],
    ],
];
