<?php

namespace App\Models;

use App\Enums\EstadoTramite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Asiento del historial de estados. Sólo se agrega. */
class TramiteHistorial extends Model
{
    protected $table = 'tramite_historial';

    public const UPDATED_AT = null;

    protected $fillable = ['tramite_id', 'de_estado', 'a_estado', 'usuario_id', 'comentario'];

    protected function casts(): array
    {
        return [
            'de_estado' => EstadoTramite::class,
            'a_estado' => EstadoTramite::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial no se edita.'));
        static::deleting(fn () => throw new LogicException('El historial no se borra.'));
    }

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
