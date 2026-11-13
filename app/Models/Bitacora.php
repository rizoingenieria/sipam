<?php

namespace App\Models;

use App\Enums\EventoBitacora;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/** Renglón de la bitácora. Inmutable: sólo se agrega. */
class Bitacora extends Model
{
    protected $table = 'bitacora';

    public const UPDATED_AT = null;

    protected $fillable = ['usuario_id', 'evento', 'modelo_tipo', 'modelo_id', 'descripcion', 'cambios', 'ip', 'agente'];

    protected function casts(): array
    {
        return ['evento' => EventoBitacora::class, 'cambios' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La bitácora no se modifica.'));
        static::deleting(fn () => throw new LogicException('La bitácora no se borra.'));
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * Anota un evento. Nunca interrumpe la operación que lo origina: si la bitácora falla,
     * se deja constancia en el log del servidor y el trabajo del usuario sigue.
     */
    public static function anotar(EventoBitacora $evento, string $descripcion, ?Model $registro = null, ?array $cambios = null, ?int $usuarioId = null): void
    {
        try {
            $request = app()->runningInConsole() ? null : request();
            static::create([
                'usuario_id' => $usuarioId ?? auth()->id(),
                'evento' => $evento,
                'modelo_tipo' => $registro ? $registro::class : null,
                'modelo_id' => $registro?->getKey(),
                'descripcion' => mb_substr($descripcion, 0, 255),
                'cambios' => $cambios ?: null,
                'ip' => $request?->ip(),
                'agente' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : 'consola',
            ]);
        } catch (Throwable $e) {
            Log::error('No se pudo anotar en la bitácora: '.$e->getMessage(), ['descripcion' => $descripcion]);
        }
    }
}
