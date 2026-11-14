<?php

namespace Tests;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Deja al usuario con la verificación en dos pasos ya activada (la exige, de fábrica, el rol administrador). */
    protected function conDosPasos(User $usuario): User
    {
        $usuario->saveAppAuthenticationSecret(AppAuthentication::make()->generateSecret());

        return $usuario;
    }
}
