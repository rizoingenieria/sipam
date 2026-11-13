<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TipoArchivo: string implements HasLabel
{
    case FotoEstrados = 'foto_estrados';          // notificación colocada en estrados (art. 34)
    case FotoLote = 'foto_lote';                  // notificación colocada en el lote (art. 34)
    case DocumentoEntregado = 'documento_entregado'; // escaneo de lo que presenta el ciudadano
    case DocumentoEmitido = 'documento_emitido';     // responsiva o carta compromiso firmada
    case Otro = 'otro';

    public function getLabel(): string
    {
        return match ($this) {
            self::FotoEstrados => 'Foto de la notificación en estrados',
            self::FotoLote => 'Foto de la notificación en el lote',
            self::DocumentoEntregado => 'Documento entregado (escaneo o foto)',
            self::DocumentoEmitido => 'Documento firmado por el ayuntamiento',
            self::Otro => 'Otro',
        };
    }

    /** @return list<string> */
    public function tiposPermitidos(): array
    {
        $imagenes = ['image/jpeg', 'image/png', 'image/webp'];

        return match ($this) {
            self::FotoEstrados, self::FotoLote => $imagenes,
            default => [...$imagenes, 'application/pdf'],
        };
    }

    /** Para decirlo en pantalla: «JPG, PNG o WEBP». */
    public function tiposEnPalabras(): string
    {
        return in_array('application/pdf', $this->tiposPermitidos(), true) ? 'JPG, PNG, WEBP o PDF' : 'JPG, PNG o WEBP';
    }
}
