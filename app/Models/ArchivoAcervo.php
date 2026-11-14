<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Un archivo escaneado del acervo (una página o un PDF de varias). Se guarda tal como se subió,
 * con su huella SHA-256; no se modifica ni se borra (la base y el respaldo lo impiden).
 */
class ArchivoAcervo extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'archivos_acervo';

    protected $fillable = ['documento_id', 'orden', 'disco', 'ruta', 'nombre_original', 'mime', 'bytes', 'sha256', 'subido_por'];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'orden' => 'integer', 'verificado_en' => 'datetime', 'integro' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (ArchivoAcervo $a): void {
            // Sólo la verificación de integridad puede escribir en un archivo ya guardado.
            if (array_diff(array_keys($a->getDirty()), ['verificado_en', 'integro']) !== []) {
                throw new LogicException('Un archivo del acervo no se modifica: sube uno nuevo.');
            }
        });
        static::deleting(fn () => throw new LogicException('Un archivo del acervo no se borra: retira el documento.'));
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoAcervo::class, 'documento_id');
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function esImagenVisible(): bool
    {
        return in_array($this->mime, ['image/jpeg', 'image/png'], true);
    }

    public function esPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function tamano(): string
    {
        return $this->bytes >= 1048576 ? number_format($this->bytes / 1048576, 1).' MB' : max(1, (int) round($this->bytes / 1024)).' KB';
    }
}
