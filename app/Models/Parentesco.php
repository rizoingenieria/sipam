<?php

namespace App\Models;

use App\Enums\LineaParentesco;
use Illuminate\Database\Eloquent\Model;

class Parentesco extends Model
{
    protected $table = 'parentescos';

    protected $fillable = ['clave', 'nombre', 'linea', 'grado', 'prelacion', 'puede_autorizar'];

    protected function casts(): array
    {
        return [
            'linea' => LineaParentesco::class,
            'grado' => 'integer',
            'prelacion' => 'integer',
            'puede_autorizar' => 'boolean',
        ];
    }
}
