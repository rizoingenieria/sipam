<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Un documento del acervo histórico: la ficha de un original en papel y sus archivos escaneados.
 * No se borra nunca; si se subió por error se «retira» con su motivo y deja de aparecer en las fichas.
 */
class DocumentoAcervo extends Model
{
    protected $table = 'documentos_acervo';

    protected $fillable = ['folio', 'tipo_id', 'titulo', 'descripcion', 'anio_desde', 'anio_hasta', 'ubicacion_fisica', 'observaciones', 'capturado_por'];

    protected function casts(): array
    {
        return ['anio_desde' => 'integer', 'anio_hasta' => 'integer', 'retirado_en' => 'datetime'];
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoDocumentoAcervo::class, 'tipo_id');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(ArchivoAcervo::class, 'documento_id')->orderBy('orden');
    }

    public function capturadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capturado_por');
    }

    public function retiradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retirado_por');
    }

    public function espacios(): MorphToMany
    {
        return $this->morphedByMany(Espacio::class, 'vinculable', 'acervo_vinculos', 'documento_id');
    }

    public function titulos(): MorphToMany
    {
        return $this->morphedByMany(Titulo::class, 'vinculable', 'acervo_vinculos', 'documento_id');
    }

    public function inhumados(): MorphToMany
    {
        return $this->morphedByMany(Inhumado::class, 'vinculable', 'acervo_vinculos', 'documento_id');
    }

    public function retirado(): bool
    {
        return $this->retirado_en !== null;
    }

    /** «1948», «1940 a 1950», «desde 1940», «hasta 1950» o nada. */
    public function periodo(): ?string
    {
        return match (true) {
            $this->anio_desde && $this->anio_hasta && $this->anio_desde !== $this->anio_hasta => "{$this->anio_desde} a {$this->anio_hasta}",
            (bool) $this->anio_desde && (! $this->anio_hasta || $this->anio_hasta === $this->anio_desde) => (string) $this->anio_desde,
            (bool) $this->anio_hasta => "hasta {$this->anio_hasta}",
            default => null,
        };
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereNull('retirado_en');
    }

    /** Documentos que cubren algún año del intervalo pedido (los sin año no entran). */
    public function scopeDelPeriodo(Builder $query, ?int $desde, ?int $hasta): Builder
    {
        return $query
            ->when($desde, fn (Builder $q) => $q->whereRaw('COALESCE(anio_hasta, anio_desde) >= ?', [$desde]))
            ->when($hasta, fn (Builder $q) => $q->whereRaw('COALESCE(anio_desde, anio_hasta) <= ?', [$hasta]));
    }
}
