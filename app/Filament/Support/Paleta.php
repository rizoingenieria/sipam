<?php

namespace App\Filament\Support;

/**
 * Paleta de SIPAM, tomada del logo (vino) y de la imagen de acceso (crema y dorado).
 * Filament pide 11 tonos por color; cada tono repite el token más cercano en vez de inventar un color.
 * Los mismos valores están como variables CSS (--sp-*) en resources/css/sipam.css: si cambias uno, cambia el otro.
 *
 * - primary: vino del logo. Botón principal, enlaces, foco y elemento activo del menú.
 * - gray:    crema, hoja, renglón y tinta. Fondos, bordes y texto.
 * - success: verde. Correcto, listo, activo.
 * - warning: dorado oscuro (el de las hojas, con contraste suficiente). Pide atención.
 * - danger:  rojo teja. Error o acción que no se deshace; se distingue del vino por tono y por claridad.
 * - info:    igual que gray.
 *
 * Contrastes medidos sobre la hoja (#fffcf9): tinta 15.5:1, tinta suave 7.3:1, vino 10.6:1, verde 4.9:1, dorado 5.3:1,
 * teja 5.1:1; texto blanco sobre el botón vino 10.9:1. Todos cumplen WCAG AA.
 */
final class Paleta
{
    // Superficies y texto.
    public const PAPER = '#faf3ec';

    public const SHEET = '#fffcf9';

    public const FIELD = '#ffffff';

    public const INK = '#2b1f22';

    public const INK_SOFT = '#5e5256';

    public const RULE = '#e8d9cc';

    public const RULE_STRONG = '#cdb5a3';

    public const TRACK = '#f3e8de';

    // Vino del logo.
    public const VINO = '#7a1029';

    public const VINO_HOVER = '#8e1b35';

    public const VINO_SOFT = '#a63a50';

    public const VINO_STRONG = '#5f0b1f';

    public const VINO_LINE = '#e4bfc6';

    public const VINO_TINT = '#f4e1e4';

    public const VINO_WASH = '#fbf0f1';

    // Estados.
    public const GUIDE = '#2e7d5b';

    public const GUIDE_STRONG = '#297052';

    public const GUIDE_TINT = '#dcebe0';

    public const GUIDE_WASH = '#e4f0e7';

    public const GUIDE_LINE = '#b9d6c3';

    public const CAUTION = '#8a6408';

    public const CAUTION_STRONG = '#765406';

    public const CAUTION_LINE = '#d8bf7f';

    public const CAUTION_TINT = '#f6ecd3';

    public const ALERT = '#c2410c';

    public const ALERT_STRONG = '#a3360a';

    public const ALERT_TINT = '#fbe6da';

    /** @return array<string, array<int, string>> */
    public static function colores(): array
    {
        $gris = self::tonos(self::SHEET, self::PAPER, self::TRACK, self::RULE, self::RULE_STRONG, self::INK_SOFT, self::INK_SOFT, self::INK, self::INK, self::INK, self::INK);

        return [
            // Filament pinta el botón con el 600 y el paso del ratón con el 500.
            'primary' => self::tonos(self::VINO_WASH, self::VINO_TINT, self::VINO_LINE, self::VINO_LINE, self::VINO_SOFT, self::VINO_HOVER, self::VINO, self::VINO_STRONG, self::VINO_STRONG, self::VINO_STRONG, self::VINO_STRONG),
            'gray' => $gris,
            'info' => $gris,
            'success' => self::tonos(self::GUIDE_WASH, self::GUIDE_TINT, self::GUIDE_LINE, self::GUIDE_LINE, self::GUIDE, self::GUIDE, self::GUIDE_STRONG, self::GUIDE_STRONG, self::GUIDE_STRONG, self::GUIDE_STRONG, self::GUIDE_STRONG),
            'warning' => self::tonos(self::CAUTION_TINT, self::CAUTION_TINT, self::CAUTION_LINE, self::CAUTION_LINE, self::CAUTION, self::CAUTION, self::CAUTION_STRONG, self::CAUTION_STRONG, self::CAUTION_STRONG, self::CAUTION_STRONG, self::CAUTION_STRONG),
            'danger' => self::tonos(self::ALERT_TINT, self::ALERT_TINT, self::ALERT_TINT, self::ALERT, self::ALERT, self::ALERT, self::ALERT, self::ALERT_STRONG, self::ALERT_STRONG, self::ALERT_STRONG, self::ALERT_STRONG),
        ];
    }

    /** @return array<int, string> */
    private static function tonos(string ...$hex): array
    {
        return array_combine([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950], $hex);
    }
}
