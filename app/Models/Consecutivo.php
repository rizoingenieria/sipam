<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consecutivo extends Model
{
    protected $table = 'consecutivos';

    protected $fillable = ['serie', 'anio', 'ultimo'];

    protected function casts(): array
    {
        return ['anio' => 'integer', 'ultimo' => 'integer'];
    }
}
