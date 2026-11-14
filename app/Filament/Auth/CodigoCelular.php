<?php

namespace App\Filament\Auth;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Illuminate\Support\Collection;
use SensitiveParameter;

/**
 * Verificación con aplicación de códigos, con códigos de recuperación fáciles de copiar a mano.
 *
 * Los de Filament mezclan l, I, 1, 0 y O y distinguen mayúsculas: copiados de una hoja impresa fallan.
 * Aquí: 12 caracteres sin ambigüedad en grupos de 4 (K7MW-3QXP-9HTD), y al escribirlos se aceptan
 * minúsculas, espacios y guiones de más. ~59 bits por código: suficiente para un código de un solo uso,
 * guardado cifrado y con límite de intentos.
 */
class CodigoCelular extends AppAuthentication
{
    public const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin I, O, 0 ni 1

    /** @return array<string> */
    public function generateRecoveryCodes(): array
    {
        return Collection::times($this->getRecoveryCodeCount(), function (): string {
            $codigo = '';
            for ($i = 0; $i < 12; $i++) {
                $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
            }

            return implode('-', str_split($codigo, 4));
        })->all();
    }

    public function verifyRecoveryCode(#[SensitiveParameter] string $recoveryCode, ?HasAppAuthenticationRecovery $user = null): bool
    {
        return parent::verifyRecoveryCode(self::normalizar($recoveryCode), $user);
    }

    /** «k7mw 3qxp-9htd » → «K7MW-3QXP-9HTD». */
    public static function normalizar(#[SensitiveParameter] string $codigo): string
    {
        $limpio = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($codigo));

        return strlen($limpio) === 12 ? implode('-', str_split($limpio, 4)) : trim($codigo);
    }
}
