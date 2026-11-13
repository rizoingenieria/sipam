<?php

namespace App\Models;

use App\Enums\TipoHorario;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Horario extends Model
{
    protected $table = 'horarios';

    protected $fillable = ['panteon_id', 'tipo', 'dia_semana', 'abre', 'cierra'];

    protected function casts(): array
    {
        return ['tipo' => TipoHorario::class, 'dia_semana' => 'integer'];
    }

    public function panteon(): BelongsTo
    {
        return $this->belongsTo(Panteon::class);
    }

    /** ¿El momento cae dentro del horario? Sin horario capturado para ese día, no. */
    public static function abierto(Panteon $panteon, TipoHorario $tipo, CarbonInterface $momento): bool
    {
        $hora = $momento->format('H:i:s');

        return static::where('panteon_id', $panteon->id)
            ->where('tipo', $tipo->value)
            ->where('dia_semana', $momento->dayOfWeekIso)
            ->where('abre', '<=', $hora)
            ->where('cierra', '>', $hora)
            ->exists();
    }
}
