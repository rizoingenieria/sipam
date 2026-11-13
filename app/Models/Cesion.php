<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cesión o donación de derechos de uso (art. 27). Un espacio sólo se cede una vez a la misma
 * persona (fr. VI): lo garantiza el índice «cesiones_una_vez_por_persona».
 */
class Cesion extends Model
{
    protected $table = 'cesiones';

    protected $fillable = ['espacio_id', 'titulo_anterior_id', 'titulo_nuevo_id', 'cesionario_id', 'fecha'];

    protected function casts(): array
    {
        return ['fecha' => 'date'];
    }

    public function espacio(): BelongsTo
    {
        return $this->belongsTo(Espacio::class);
    }

    public function tituloAnterior(): BelongsTo
    {
        return $this->belongsTo(Titulo::class, 'titulo_anterior_id');
    }

    public function tituloNuevo(): BelongsTo
    {
        return $this->belongsTo(Titulo::class, 'titulo_nuevo_id');
    }

    public function cesionario(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'cesionario_id');
    }
}
