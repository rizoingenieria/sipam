<?php

namespace App\Models;

use App\Enums\TipoArchivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Evidencia de un trámite. No se edita; se quita sólo mientras el trámite está abierto.
 * Con el trámite cerrado, la evidencia queda fija para auditoría.
 */
class TramiteArchivo extends Model
{
    protected $table = 'tramite_archivos';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tramite_id', 'tramite_requisito_id', 'tipo', 'disco', 'ruta', 'nombre_original',
        'mime', 'bytes', 'sha256', 'descripcion', 'subido_por',
    ];

    protected function casts(): array
    {
        return ['tipo' => TipoArchivo::class, 'bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Una evidencia no se modifica; súbela de nuevo.'));

        static::deleting(function (TramiteArchivo $a): void {
            if ($a->tramite->estado->esTerminal()) {
                throw new LogicException('El trámite ya está cerrado: su evidencia no se puede quitar.');
            }
        });

        // El archivo físico se borra sólo si el borrado del registro se confirma.
        static::deleted(fn (TramiteArchivo $a) => DB::afterCommit(fn () => Storage::disk($a->disco)->delete($a->ruta)));
    }

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class);
    }

    public function requisito(): BelongsTo
    {
        return $this->belongsTo(TramiteRequisito::class, 'tramite_requisito_id');
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    /** ¿El archivo en disco sigue siendo el que se subió? */
    public function integro(): bool
    {
        $disco = Storage::disk($this->disco);

        return $disco->exists($this->ruta) && hash('sha256', (string) $disco->get($this->ruta)) === $this->sha256;
    }
}
