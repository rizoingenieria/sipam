<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cargo extends Model
{
    protected $table = 'cargos';

    protected $fillable = ['clave', 'nombre', 'firma_documentos', 'orden'];

    protected function casts(): array
    {
        return ['firma_documentos' => 'boolean', 'orden' => 'integer'];
    }

    public function firmantes(): HasMany
    {
        return $this->hasMany(Firmante::class);
    }

    /** Quién ocupaba el cargo en una fecha (la de emisión del documento). */
    public function firmanteEn(?CarbonInterface $fecha = null): ?Firmante
    {
        return $this->firmantes()->vigenteEn($fecha)->first();
    }

    public static function firmante(string $clave, ?CarbonInterface $fecha = null): ?Firmante
    {
        return static::where('clave', $clave)->first()?->firmanteEn($fecha);
    }
}
