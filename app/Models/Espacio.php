<?php

namespace App\Models;

use App\Enums\EstadoTitulo;
use App\Enums\TipoEspacio;
use App\Models\Concerns\TieneAcervo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Espacio extends Model
{
    use TieneAcervo;

    protected $table = 'espacios';

    protected $fillable = [
        'lote_id', 'tipo', 'numero', 'largo_m', 'ancho_m', 'profundidad_m',
        'es_fosa_comun', 'es_monumento_historico', 'habilitado', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoEspacio::class,
            'largo_m' => 'decimal:2',
            'ancho_m' => 'decimal:2',
            'profundidad_m' => 'decimal:2',
            'es_fosa_comun' => 'boolean',
            'es_monumento_historico' => 'boolean',
            'habilitado' => 'boolean',
        ];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function titulos(): HasMany
    {
        return $this->hasMany(Titulo::class);
    }

    /** El único título vigente (lo garantiza un índice UNIQUE en la base de datos). */
    public function tituloVigente(): HasOne
    {
        return $this->hasOne(Titulo::class)->where('estado', EstadoTitulo::Vigente->value);
    }

    /** Inhumados cuya ubicación actual es este espacio (incluye restos áridos en sitio). */
    public function inhumados(): HasMany
    {
        return $this->hasMany(Inhumado::class);
    }

    /** Último sepultado: base de los plazos de inhumación y exhumación. */
    public function ultimoInhumado(): HasOne
    {
        return $this->hasOne(Inhumado::class)->latestOfMany('fecha_inhumacion');
    }

    /**
     * Clave natural legible: PANTEÓN-SECCIÓN-LOTE-ESPACIO.
     * Requiere cargar lote.seccion.panteon para no disparar consultas en listados.
     */
    protected function claveCompleta(): Attribute
    {
        return Attribute::get(fn (): string => implode('-', [
            $this->lote->seccion->panteon->clave,
            $this->lote->seccion->clave,
            $this->lote->numero,
            $this->numero,
        ]));
    }
}
