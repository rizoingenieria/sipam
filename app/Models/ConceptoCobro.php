<?php

namespace App\Models;

use App\Enums\CategoriaConcepto;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConceptoCobro extends Model
{
    protected $table = 'conceptos_cobro';

    protected $fillable = ['clave', 'nombre', 'categoria', 'por_seccion', 'unidad', 'fundamento', 'activo', 'orden'];

    protected function casts(): array
    {
        return [
            'categoria' => CategoriaConcepto::class,
            'por_seccion' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(Cuota::class, 'concepto_id');
    }

    /** Cuota aplicable en una fecha; null si el tabulador no la tiene capturada. */
    public function cuotaPara(?Seccion $seccion = null, ?CarbonInterface $fecha = null): ?Cuota
    {
        return Cuota::vigentePara($this, $seccion, $fecha);
    }
}
