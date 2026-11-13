<?php

namespace App\Models;

use App\Enums\RolTitularidad;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Titularidad extends Pivot
{
    protected $table = 'titularidades';

    public $incrementing = true;

    protected $fillable = ['titulo_id', 'persona_id', 'rol', 'orden'];

    protected function casts(): array
    {
        return [
            'rol' => RolTitularidad::class,
            'orden' => 'integer',
        ];
    }

    public function titulo(): BelongsTo
    {
        return $this->belongsTo(Titulo::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
