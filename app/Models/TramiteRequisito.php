<?php

namespace App\Models;

use App\Enums\EstadoRequisitoEntregado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Requisito exigido a un trámite (versión del catálogo copiada al capturar) y su entrega. */
class TramiteRequisito extends Model
{
    protected $table = 'tramite_requisitos';

    protected $fillable = [
        'tramite_id', 'requisito_id', 'ordinal', 'estado', 'persona_id', 'fecha_expedicion',
        'folio_documento', 'observaciones', 'revisado_por', 'revisado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoRequisitoEntregado::class,
            'ordinal' => 'integer',
            'fecha_expedicion' => 'date',
            'revisado_en' => 'datetime',
        ];
    }

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class);
    }

    public function requisito(): BelongsTo
    {
        return $this->belongsTo(Requisito::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }
}
