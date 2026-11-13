<?php

namespace App\Models;

use App\Enums\ParteTramite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TramiteParticipante extends Model
{
    protected $table = 'tramite_participantes';

    protected $fillable = [
        'tramite_id', 'persona_id', 'papel', 'parentesco_id', 'anios_residencia',
        'comparecio_en', 'comparecencia_registrada_por',
    ];

    protected function casts(): array
    {
        return [
            'papel' => ParteTramite::class,
            'anios_residencia' => 'integer',
            'comparecio_en' => 'datetime',
        ];
    }

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function parentesco(): BelongsTo
    {
        return $this->belongsTo(Parentesco::class);
    }

    public function comparecio(): bool
    {
        return $this->comparecio_en !== null;
    }
}
